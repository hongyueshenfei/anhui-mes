<?php

namespace App\Modules\Production\Services;

use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Production\Events\ReportSaved;
use App\Modules\Production\Exceptions\ReportException;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use App\Modules\System\Services\ConfigService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 报工服务（C2/C3/C4/C5/C6/C7 核心）
 *
 * 台账纪律（D3）：只增不改，仅 INSERT；更正 = 冲减负记录（sign=-1，原因必填）。
 * - 合格/次品口径：qty=合格数（计件工资与模具寿命折算基准），defect_qty=次品数
 * - 当前阶段：仅班组长/统计员（及系统管理员）可提交，暂不区分个人作业人
 * - 超量校验：累计净合格 + 本次 ≤ 任务量 × (1 + over_report_pct%)，超限 422
 * - 防重：POST /reports/verify 5 分钟窗口同 task+worker+数量重复提示
 * - 联动：ReportSaved 事件 → 任务进度/模具寿命（UpdateTaskProgress/UpdateMoldLife）
 */
class ReportService
{
    /** 防重窗口（秒） */
    public const DEDUPE_WINDOW_SECONDS = 300;

    public function __construct(
        private readonly TaskStatusService $taskStatusService,
        private readonly MoldLifeService $moldLifeService,
    ) {
    }

    /**
     * 提交报工（正常/补报/离线补传/冲减）
     *
     * @param  array  $data  [task_id, qty, defect_qty?, worker_id?, source?, offline_flag?, remark?, report_time?, start_time?, end_time?, work_date?, shift_code?, client_request_id?]
     * @return array{report: WorkReport, task: array}
     *
     * @throws ReportException
     */
    public function report(array $data, \App\Models\User $operator): array
    {
        $taskId = (int) $data['task_id'];
        $qty = (int) ($data['qty'] ?? 0);
        $defectQty = (int) ($data['defect_qty'] ?? 0);
        $source = $data['source'] ?? WorkReport::SOURCE_CLICK;
        $workerId = isset($data['worker_id']) && $data['worker_id'] !== null ? (int) $data['worker_id'] : null;
        $startTime = ! empty($data['start_time']) ? Carbon::parse($data['start_time']) : null;
        $endTime = ! empty($data['end_time']) ? Carbon::parse($data['end_time']) : null;
        if ($startTime !== null && $endTime !== null && $endTime->lt($startTime)) {
            throw new ReportException('结束时间不能早于开始时间', 'invalid_report_time_range');
        }

        // report_time 继续保留为流水提交/排序时间；新报工未单独传入时取作业结束时间。
        $reportTime = isset($data['report_time'])
            ? Carbon::parse($data['report_time'])
            : ($endTime?->copy() ?? now());
        $startTime ??= $reportTime->copy();
        $endTime ??= $reportTime->copy();
        $workDate = isset($data['work_date'])
            ? Carbon::createFromFormat('Y-m-d', (string) $data['work_date'])->startOfDay()
            : $startTime->copy()->startOfDay();
        $clientRequestId = isset($data['client_request_id']) ? trim((string) $data['client_request_id']) : null;

        // 实际报工不能落在当前时刻之后；离线补传只允许提交历史作业时间。
        if ($reportTime->isAfter(now())) {
            throw new ReportException('报工时间不能晚于当前时间', 'future_report_time');
        }
        if ($startTime->isAfter(now())) {
            throw new ReportException('实际作业开始时间不能晚于当前时间', 'future_report_start_time');
        }
        if ($endTime->isAfter(now())) {
            throw new ReportException('实际作业结束时间不能晚于当前时间', 'future_report_end_time');
        }
        if ($workDate->isAfter(now()->startOfDay())) {
            throw new ReportException('实际作业日期不能晚于今天', 'future_work_date');
        }

        if ($qty === 0 && $defectQty === 0) {
            throw new ReportException('报工数量不能为 0', 'zero_qty');
        }

        $task = ProdTask::with('mold', 'product')->find($taskId);
        if (! $task) {
            throw new ReportException('任务不存在', 'task_not_found', [], 404);
        }

        // 离线补传/重复点击使用客户端幂等键直接返回原流水，不重复触发进度、库存和模具寿命联动。
        if ($clientRequestId) {
            $existing = WorkReport::with('worker:id,name,employee_no', 'operator:id,name,employee_no')
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existing) {
                return [
                    'report' => $existing,
                    'task' => $this->taskBrief($task->fresh()),
                    'is_correction' => (int) $existing->sign === -1,
                    'stock_in_correction' => null,
                ];
            }
        }

        $this->assertReportable($task);

        // 冲减：负数量（qty 或 defect_qty 为负）→ sign=-1，原因必填
        $isCorrection = $qty < 0 || $defectQty < 0;
        $sign = $isCorrection ? -1 : 1;
        if ($isCorrection && empty(trim((string) ($data['remark'] ?? '')))) {
            throw new ReportException('冲减必须填写原因', 'correction_remark_required');
        }

        // 当前阶段统一由班组长/统计员填报，不写入个人作业人归属。
        $this->assertReportOperator($operator, $workerId);

        // 现场只能报机台扫码后选定的工序，防止把数量挂到同工单的其他工序或其他机台。
        $this->assertScheduleMatch(
            $task,
            isset($data['schedule_id']) ? (int) $data['schedule_id'] : null,
            isset($data['machine_id']) ? (int) $data['machine_id'] : null,
            $isCorrection,
        );
        if ($sign === 1) {
            // 任务级成品报工最终归入末道工序，也必须继承末道资源的班制校验。
            $shiftScheduleId = isset($data['schedule_id'])
                ? (int) $data['schedule_id']
                : (int) ($task->schedules()
                    ->where('is_interrupted', 0)
                    ->orderByDesc('seq')
                    ->orderByDesc('id')
                    ->value('id') ?? 0);
            if ($shiftScheduleId > 0) {
                $this->assertWithinResourceShift($shiftScheduleId, $startTime, $endTime);
            }
        }

        // 工序链校验（2026-08-31 业务确认）：下道工序完工量 ≤ 上道工序完工量
        if ($sign === 1 && isset($data['schedule_id'])) {
            $this->assertProcessChain($task, (int) $data['schedule_id'], $qty);
        }

        // 超量校验（C4 可配置）：冲减不设上限。
        // qty 已经带正负号，不能再次乘 sign，否则冲减会被误判为新增数量。
        $this->taskStatusService->assertWithinAllowance($task, $qty);

        // 库存一致性联动（QA P1）在事务内执行（见下方闭包首行），保证报工与回冲原子
        $stockInCorrection = null;

        // 机台/产线：默认带出排产机台；支持手动报工指定（前端可修改）
        $machineId = isset($data['machine_id']) && $data['machine_id'] !== null
            ? (int) $data['machine_id']
            : $task->schedules()->whereNotNull('machine_id')->value('machine_id');

        $report = DB::transaction(function () use ($data, $task, $qty, $defectQty, $sign, $source, $workerId, $machineId, $operator, $isCorrection, $reportTime, $startTime, $endTime, $workDate, $clientRequestId, &$stockInCorrection) {
            // 冲减 × 已入库联动（QA P1）：事务内执行，报工与红字回冲原子提交
            if ($qty < 0) {
                $stockInCorrection = $this->assertStockInConsistencyAfterCorrection(
                    $task,
                    $qty,
                    $operator,
                    (string) ($data['remark'] ?? ''),
                    isset($data['schedule_id']) ? (int) $data['schedule_id'] : null,
                );
            }

            $report = WorkReport::create([
                'task_id' => $task->id,
                'schedule_id' => isset($data['schedule_id']) ? (int) $data['schedule_id'] : null,
                'product_id' => $task->product_id,
                'machine_id' => $machineId,
                'qty' => $qty,
                'defect_qty' => $defectQty,
                'sign' => $sign,
                'report_by' => $operator->id,
                // 暂不细分到个人；report_by 保留实际填报操作人用于审计。
                'worker_id' => null,
                'report_time' => $reportTime,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'work_date' => $workDate->toDateString(),
                'shift_code' => isset($data['shift_code']) ? trim((string) $data['shift_code']) : null,
                'client_request_id' => $clientRequestId,
                'source' => $source,
                'offline_flag' => (int) ($data['offline_flag'] ?? 0),
                'remark' => trim((string) ($data['remark'] ?? '')) ?: null,
            ]);

            // 事件联动（任务进度/模具寿命/预测）；netGoodQty 直接取 qty
            // （冲减已为负值，符号口径与 SUM(qty) 一致）。任一联动失败都必须让
            // 当前报工事务回滚，不能出现“接口成功但闭环数据没更新”的假成功。
            try {
                event(new ReportSaved($report, $task, $qty));
            } catch (\Throwable $e) {
                Log::error('报工联动失败，报工事务将回滚', [
                    'task_id' => $task->id,
                    'report_id' => $report->id,
                    'error' => $e->getMessage(),
                ]);
                throw new ReportException(
                    '报工联动失败，已回滚本次报工：'.$e->getMessage(),
                    'report_linkage_failed',
                    ['task_id' => $task->id, 'report_id' => $report->id],
                    500,
                );
            }

            return $report;
        });

        return [
            'report' => $report->load('worker:id,name,employee_no', 'operator:id,name,employee_no'),
            'task' => $this->taskBrief($task->fresh()),
            'is_correction' => $isCorrection,
            'stock_in_correction' => $stockInCorrection,
        ];
    }

    /**
     * 冲减 × 已入库联动校验（QA P1 修复）：
     *
     * 冲减后净完成量(qty_sum + 本次冲减) < 已入库量 → 自动创建红字冲减入库单（负 qty），
     * 并将任务 stocked_qty 同步回退，保证 完成量 ≥ 已入库量 恒成立，账实相符。
     *
     * @return array{stock_in: \App\Models\StockIn, needed: int}|null 触发联动时返回
     *
     * @throws ReportException 冲减后完成量为负（无法通过冲减入库单自洽）
     */
    private function assertStockInConsistencyAfterCorrection(
        $task,
        int $correctionQty,
        \App\Models\User $operator,
        string $remark,
        ?int $scheduleId = null,
    ): ?array
    {
        // 入库只认任务级报工和最后一道工序。修正中间工序时，成品完成量
        // 不变；若汇总任务全部工序，会把过程量误当成可入库量，漏掉末工序冲减的回冲。
        $lastSeq = TaskSchedule::where('task_id', $task->id)->max('seq');
        $lastScheduleIds = $lastSeq === null
            ? collect()
            : TaskSchedule::where('task_id', $task->id)->where('seq', $lastSeq)->pluck('id');
        $affectsFinishedQty = $scheduleId === null
            || ($lastScheduleIds->isNotEmpty() && $lastScheduleIds->contains($scheduleId));
        if (! $affectsFinishedQty) {
            return null;
        }

        $currentNet = (int) $task->reports()
            ->where(function ($query) use ($lastScheduleIds) {
                $query->whereNull('schedule_id');
                if ($lastScheduleIds->isNotEmpty()) {
                    $query->orWhereIn('schedule_id', $lastScheduleIds);
                }
            })
            ->sum(\Illuminate\Support\Facades\DB::raw('qty'));
        $afterNet = $currentNet + $correctionQty; // correctionQty < 0
        $stocked = (int) $task->stocked_qty;

        if ($afterNet < 0) {
            throw new ReportException(
                "冲减后任务完成量（{$afterNet}）不能为负，请核对报工流水",
                'correction_below_zero',
                ['task_id' => $task->id, 'after_done_qty' => $afterNet],
            );
        }

        if ($afterNet >= $stocked) {
            return null; // 库存仍可覆盖，无需联动
        }

        // 需要回冲的入库量：仅冲减到与完成量一致的边界，不超冲
        $needed = $stocked - $afterNet;

        $stockIn = \App\Models\StockIn::create([
            'ins_no' => app(\App\Modules\Warehouse\Services\StockInService::class)->generateInsNo(),
            'task_id' => $task->id,
            'product_id' => $task->product_id,
            'qty' => -$needed,
            'ins_status' => \App\Models\StockIn::INS_STATUS_CONFIRMED,
            'k3_push_status' => \App\Models\StockIn::K3_QUEUED, // 红字回冲需人工确认后推送金蝶
            'created_by' => $operator->id,
            'remark' => '报工冲减联动回冲（'.mb_substr($remark, 0, 100).'）',
        ]);

        $task->decrement('stocked_qty', $needed);

        return ['stock_in' => $stockIn, 'needed' => $needed];
    }

    /**
     * 防重校验（C7 弱网补传防重 & 重复点选提示）
     *
     * @return array{duplicated: bool, message: string, latest_at: ?string}
     */
    public function verifyDuplication(int $taskId, int $qty, ?int $workerId = null, ?int $reportBy = null): array
    {
        $query = WorkReport::where('task_id', $taskId)
            ->where('qty', $qty)
            ->where('sign', $qty < 0 ? -1 : 1)
            ->where('report_time', '>=', now()->subSeconds(self::DEDUPE_WINDOW_SECONDS));

        if ($workerId !== null) {
            $query->where('worker_id', $workerId);
        } elseif ($reportBy !== null) {
            $query->where('report_by', $reportBy);
        }

        $latest = $query->latest('report_time')->first();

        if ($latest !== null) {
            return [
                'duplicated' => true,
                'message' => sprintf(
                    '%s 分钟内已有相同报工记录（%s %s件 by %s），请确认是否重复提交',
                    intdiv(self::DEDUPE_WINDOW_SECONDS, 60),
                    $latest->source_label,
                    $latest->qty,
                    $latest->worker?->name ?? $latest->worker_id,
                ),
                'latest_at' => $latest->report_time?->format('Y-m-d H:i:s'),
            ];
        }

        return ['duplicated' => false, 'message' => '无重复记录', 'latest_at' => null];
    }

    /**
     * 报工台账列表（PC 端）
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function index(array $filters = [], int $perPage = 15)
    {
        return WorkReport::query()
            // 任务机台在 task_schedules，prod_tasks 无 machine_id（历史残留字段已移除）
            ->with(['task:id,task_no,product_id,qty,status', 'task.product:id,code,name', 'schedule:id,task_id,seq,op_type,op_name', 'machine:id,code,name', 'worker:id,name,employee_no,workshop_id', 'worker.workshop:id,name', 'operator:id,name,employee_no'])
            ->when($filters['task_id'] ?? null, fn ($q, $v) => $q->where('task_id', (int) $v))
            ->when($filters['worker_id'] ?? null, fn ($q, $v) => $q->where('worker_id', (int) $v))
            ->when($filters['machine_id'] ?? null, fn ($q, $v) => $q->where('machine_id', (int) $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['sign'] ?? null, fn ($q, $v) => $q->where('sign', (int) $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('report_time', '>=', Carbon::parse($v)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('report_time', '<=', Carbon::parse($v)->endOfDay()))
            ->orderByDesc('report_time')
            ->paginate(min($perPage, 100));
    }

    /**
     * 报工统计汇总：按实际作业日期 + 任务 + 工序 + 机台/产线聚合。
     *
     * 报工流水只增不改，因此统计直接对 work_reports.qty/defect_qty 求和，
     * 冲减记录以负数抵扣；不读取任务冗余完成量，避免统计口径与台账脱节。
     *
     * @return array{totals: array<string, int>, items: list<array>}
     */
    public function summary(array $filters = []): array
    {
        $reports = WorkReport::query()
            ->with([
                'task:id,task_no,order_line_id,product_id,qty',
                'task.product:id,code,name',
                'task.line.order:id,order_no',
                'schedule:id,task_id,seq,op_type,op_name,machine_id,qty,plan_start,plan_end',
                'machine:id,code,name',
            ])
            // 统计按实际作业日期（日结归属）筛选，不按录入时间筛选。
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('work_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('work_date', '<=', $date))
            ->when($filters['keyword'] ?? null, function ($q, $keyword) {
                $q->where(function ($query) use ($keyword) {
                    $query->whereHas('task', fn ($task) => $task->where('task_no', 'like', '%'.$keyword.'%'))
                        ->orWhereHas('task.product', fn ($product) => $product
                            ->where('code', 'like', '%'.$keyword.'%')
                            ->orWhere('name', 'like', '%'.$keyword.'%'))
                        ->orWhereHas('task.line.order', fn ($order) => $order->where('order_no', 'like', '%'.$keyword.'%'));
                });
            })
            ->when($filters['machine_id'] ?? null, fn ($q, $machineId) => $q->where('machine_id', (int) $machineId))
            ->when($filters['machine_keyword'] ?? null, fn ($q, $keyword) => $q->whereHas('machine', fn ($machine) => $machine
                ->where('code', 'like', '%'.$keyword.'%')
                ->orWhere('name', 'like', '%'.$keyword.'%')))
            ->orderByDesc('work_date')
            ->orderByDesc('report_time')
            ->get();

        $lastSeqByTask = TaskSchedule::query()
            ->whereIn('task_id', $reports->pluck('task_id')->unique()->values())
            ->selectRaw('task_id, MAX(seq) AS last_seq')
            ->groupBy('task_id')
            ->pluck('last_seq', 'task_id');
        $finishedReports = $reports->filter(function (WorkReport $report) use ($lastSeqByTask): bool {
            // 任务级报工属于成品口径；指定工序时只认该任务最后一道工序。
            return $report->schedule_id === null
                || (int) $report->schedule?->seq === (int) $lastSeqByTask->get($report->task_id);
        });

        $groups = $reports->groupBy(fn (WorkReport $report) => implode('|', [
            $report->work_date?->format('Y-m-d') ?? '-',
            $report->task_id,
            $report->schedule_id ?? 'task',
            $report->machine_id ?? 'none',
        ]));

        $items = $groups->map(function ($group): array {
            /** @var WorkReport $first */
            $first = $group->first();
            $task = $first->task;
            $schedule = $first->schedule;
            $machine = $first->machine;
            $qualifiedQty = (int) $group->sum('qty');
            $defectQty = (int) $group->sum('defect_qty');
            $plannedQty = (int) ($schedule?->qty ?? $task?->qty ?? 0);
            $lastReport = $group->sortByDesc('report_time')->first();

            return [
                'work_date' => $first->work_date?->format('Y-m-d'),
                'task_id' => (int) $first->task_id,
                'task_no' => $task?->task_no,
                'order_no' => $task?->line?->order?->order_no,
                'product' => $task?->product ? [
                    'id' => $task->product->id,
                    'code' => $task->product->code,
                    'name' => $task->product->name,
                ] : null,
                'operation' => $schedule ? [
                    'seq' => (int) $schedule->seq,
                    'name' => $schedule->op_name ?? $schedule->op_type,
                ] : null,
                'machine' => $machine ? [
                    'id' => $machine->id,
                    'code' => $machine->code,
                    'name' => $machine->name,
                ] : null,
                'planned_qty' => $plannedQty,
                'qualified_qty' => $qualifiedQty,
                'defect_qty' => $defectQty,
                'total_qty' => $qualifiedQty + $defectQty,
                'completion_pct' => $plannedQty > 0
                    ? min(100, max(0, round($qualifiedQty / $plannedQty * 100, 1)))
                    : 0,
                'report_count' => $group->count(),
                'normal_count' => $group->where('sign', 1)->count(),
                'correction_count' => $group->where('sign', -1)->count(),
                'last_report_time' => $lastReport?->report_time?->format('Y-m-d H:i:s'),
            ];
        })->sortByDesc(fn (array $item) => implode('|', [
            $item['work_date'] ?? '-',
            $item['last_report_time'] ?? '-',
            $item['task_no'] ?? '-',
        ]))->values()->all();

        return [
            'totals' => [
                'row_count' => count($items),
                'task_count' => $reports->pluck('task_id')->unique()->count(),
                'report_count' => $reports->count(),
                'qualified_qty' => (int) $reports->sum('qty'),
                'defect_qty' => (int) $reports->sum('defect_qty'),
                'total_qty' => (int) $reports->sum(fn (WorkReport $report) => (int) $report->qty + (int) $report->defect_qty),
                'finished_qualified_qty' => (int) $finishedReports->sum('qty'),
                'finished_defect_qty' => (int) $finishedReports->sum('defect_qty'),
                'correction_count' => $reports->where('sign', -1)->count(),
            ],
            'items' => $items,
        ];
    }

    /**
     * 校验任务可报工（未排产不可报；已锁定/已完成正常可报）
     */
    private function assertReportable(ProdTask $task): void
    {
        if ($task->status === ProdTask::STATUS_UNSCHEDULED) {
            throw new ReportException("任务 {$task->task_no} 未排产，无法报工（请先排产）", 'task_unscheduled');
        }
        if ($task->status === ProdTask::STATUS_DONE && now()->diffInDays($task->plan_end ?? now()) > 1) {
            // 已完成任务允许补报窗口内冲减；长时间后的新报工走补报提醒（不硬拒，保留业务弹性）
        }
    }

    /**
     * 当前报工操作人校验：只允许班组长、统计员或系统管理员提交。
     * worker_id 暂时不接受，避免新流水继续产生个人归属数据。
     */
    private function assertReportOperator(\App\Models\User $operator, ?int $workerId): void
    {
        $allowed = in_array($operator->job_type, [
            \App\Models\User::JOB_ADMIN,
            \App\Models\User::JOB_LEADER,
            \App\Models\User::JOB_STATISTICIAN,
        ], true) || $operator->hasAnyRole(['admin', 'leader', 'statistician']);

        if (! $allowed) {
            throw new ReportException('当前仅班组长或统计员可以报工，工人暂不支持自行报工', 'report_operator_required', [], 403);
        }

        if ($workerId !== null) {
            throw new ReportException('当前报工暂不区分个人，请不要填写 worker_id', 'worker_attribution_disabled');
        }
    }

    /**
     * 校验现场选中的机台日任务。
     * schedule_id 一旦传入，必须属于当前任务；machine_id 传入时还必须与该排程机台一致。
     */
    private function assertScheduleMatch(ProdTask $task, ?int $scheduleId, ?int $machineId, bool $isCorrection = false): void
    {
        if ($scheduleId === null) {
            return;
        }

        $schedule = $task->schedules()->whereKey($scheduleId)->first();
        if (! $schedule) {
            throw new ReportException('报工工序不属于该任务', 'schedule_task_mismatch');
        }
        if (! $isCorrection && (int) $schedule->is_interrupted === 1) {
            throw new ReportException('该工序已被插单打断，仅允许对历史报工做冲减更正', 'schedule_interrupted');
        }
        if (! $isCorrection && $schedule->status === TaskSchedule::STATUS_DONE) {
            throw new ReportException('该工序已完工，不能继续新增报工；如需更正请提交负数冲减', 'schedule_completed');
        }
        if ($machineId !== null && (int) $schedule->machine_id !== $machineId) {
            throw new ReportException('报工机台与排程机台不一致', 'schedule_machine_mismatch', [
                'schedule_id' => $scheduleId,
                'schedule_machine_id' => $schedule->machine_id,
                'request_machine_id' => $machineId,
            ]);
        }
    }

    /**
     * 白班产线的单次报工必须落在同一白班窗口内，避免把非生产时段
     * 误记成实际作业。两班制成型机允许跨夜。
     */
    private function assertWithinResourceShift(int $scheduleId, Carbon $startTime, Carbon $endTime): void
    {
        $schedule = TaskSchedule::with('machine')->find($scheduleId);
        if (! $schedule || $schedule->machine?->shift_mode !== 'day') {
            return;
        }

        $period = ConfigService::shiftPeriods('day');
        $workStart = $startTime->copy()->setTimeFromTimeString((string) ($period['start'] ?? '08:00'));
        $workEnd = $startTime->copy()->setTimeFromTimeString((string) ($period['end'] ?? '17:00'));
        $sameDay = $startTime->toDateString() === $endTime->toDateString();
        $insideWindow = $sameDay && $startTime->gte($workStart) && $endTime->lte($workEnd);

        if (! $insideWindow) {
            throw new ReportException(
                sprintf(
                    '该资源为白班制，实际作业时间必须在 %s-%s 内，不能填写跨夜或夜间时间',
                    $period['start'] ?? '08:00',
                    $period['end'] ?? '17:00',
                ),
                'report_outside_shift',
                [
                    'schedule_id' => $scheduleId,
                    'machine_code' => $schedule->machine?->code,
                    'shift_mode' => 'day',
                    'shift_start' => $period['start'] ?? '08:00',
                    'shift_end' => $period['end'] ?? '17:00',
                ],
            );
        }
    }

    /**
     * 资源强对应（客户确认「报工同样强对应」）：
     *
     * 报工人（实际作业人）所属车间必须与任务资源一致，否则 422（error_code=resource_mismatch_report）。
     * - 机台线：任务须有对应车间成型机台排程（machine.workshop_id === user.workshop_id，如 I-01/injection）
     * - 人工线：任务须有对应车间产线排程，且 workshop_id === user.workshop_id
     * - 无车间用户：管理岗（非 worker 岗位）跳过校验保留管理代录通道；普通工人必须归属车间
     *
     * 代报同样校验实际作业人（worker_id）。
     *
     * @throws ReportException
     */
    private function assertResourceMatch(ProdTask $task, int $effectiveWorkerId, ?int $overrideMachineId = null): void
    {
        $worker = \App\Models\User::with('workshop')->find($effectiveWorkerId);
        if (! $worker) {
            return;
        }

        if (! $worker->workshop_id) {
            // 无车间用户：管理岗（非 worker 岗位）跳过；普通工人必须归属车间
            if ($worker->job_type !== \App\Models\User::JOB_WORKER) {
                return;
            }
            throw new ReportException('报工人未归属车间，无法报工（请先在车间管理中分配车间）', 'worker_workshop_required');
        }

        $workshopId = (int) $worker->workshop_id;

        // 手动报工可指定机台/产线：优先按所选资源校验车间匹配
        $matched = false;
        $schedules = collect();
        if ($overrideMachineId) {
            $machine = \App\Models\Machine::with('workshop')->find($overrideMachineId);
            $matched = $machine !== null && (int) $machine->workshop_id === $workshopId;
        } else {
            $schedules = $task->schedules()
                ->with(['machine:id,code,name,type,workshop_id'])
                ->get();

            $matched = $schedules->contains(function ($s) use ($workshopId) {
                if (! $s->machine_id || ! $s->machine) {
                    return false;
                }

                return (int) $s->machine->workshop_id === $workshopId;
            });
        }

        if (! $matched) {
            throw new ReportException(
                "报工人所属车间「{$worker->workshop->name}」与该任务排程资源不匹配，请核对任务归属产线/机台",
                'resource_mismatch_report',
                [
                    'task_id' => $task->id,
                    'worker_id' => $worker->id,
                    'worker_workshop_id' => $workshopId,
                    'worker_workshop' => $worker->workshop?->name,
                    'task_schedules' => $schedules->map(fn ($s) => [
                        'seq' => $s->seq,
                        'op_type' => $s->op_type,
                        'machine_id' => $s->machine_id,
                        'machine_code' => $s->machine?->code,
                        'machine_type' => $s->machine?->type,
                        'machine_workshop_id' => $s->machine?->workshop_id,
                        'machine_workshop' => $s->machine?->workshop_name,
                    ])->all(),
                ],
            );
        }
    }

    /**
     * 工序链校验（2026-08-31 业务确认）：
     * 下道工序完成数量不能大于上道工序的完成数量（后道 ≤ 前道）。
     * 首道工序无前道不校验；任务级报工（schedule_id=null）不参与。
     */
    private function assertProcessChain(ProdTask $task, int $scheduleId, int $qty): void
    {
        $schedule = \App\Models\TaskSchedule::find($scheduleId);
        if (! $schedule || $schedule->task_id !== $task->id) {
            throw new ReportException('报工工序不属于该任务', 'schedule_task_mismatch');
        }

        $prevSeq = \App\Models\TaskSchedule::where('task_id', $task->id)
            ->where('seq', '<', $schedule->seq)
            ->max('seq');

        if ($prevSeq === null) {
            return; // 首道工序无前道，不校验
        }

        // 插单打断会让同一道前序工序同时存在历史留档段和当前剩余段；
        // 后道可报数量应按该工序所有排程段的净报工量汇总，不能只取一条记录。
        $prevScheduleIds = \App\Models\TaskSchedule::where('task_id', $task->id)
            ->where('seq', $prevSeq)
            ->pluck('id');
        $prevDone = (int) WorkReport::where('task_id', $task->id)
            ->whereIn('schedule_id', $prevScheduleIds)
            ->sum('qty');
        // 当前工序被插单拆成历史段+剩余段时，也必须汇总同一 seq 的全部排程段，
        // 不能只看本次报工所在的那一条排程。
        $currentScheduleIds = \App\Models\TaskSchedule::where('task_id', $task->id)
            ->where('seq', $schedule->seq)
            ->pluck('id');
        $curDone = (int) WorkReport::where('task_id', $task->id)
            ->whereIn('schedule_id', $currentScheduleIds)
            ->sum('qty');

        if ($curDone + $qty > $prevDone) {
            throw new ReportException(
                "下道工序「{$schedule->op_name}」完工量（{$curDone} + {$qty}）不能超过上道工序完工量（{$prevDone}）",
                'process_chain_exceed',
                [
                    'task_id' => $task->id,
                    'schedule_id' => $scheduleId,
                    'prev_seq' => $prevSeq,
                    'prev_done' => $prevDone,
                    'current_done' => $curDone,
                    'request_qty' => $qty,
                ],
            );
        }
    }

    private function taskBrief(ProdTask $task): array
    {
        return [
            'id' => $task->id,
            'task_no' => $task->task_no,
            'product_code' => $task->product->code ?? '',
            'product_name' => $task->product->name ?? '',
            'qty' => $task->qty,
            'done_qty' => $task->done_qty,
            'stocked_qty' => $task->stocked_qty ?? 0,
            'status' => $task->status,
            'deviation_pct' => (float) $task->deviation_pct,
        ];
    }
}
