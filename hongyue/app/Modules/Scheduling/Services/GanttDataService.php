<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Machine;
use App\Models\Mold;
use App\Models\PlmRoute;
use App\Models\ProdTask;
use App\Models\RouteOp;
use App\Models\WorkReport;
use App\Modules\Plm\Services\CapacityCalcService;
use App\Modules\System\Services\ConfigService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * 甘特数据服务（B2/B9：任务池 / 机台产线时间线 / 明细双视图数据源）
 */
class GanttDataService
{
    public function __construct(
        private readonly ScheduleEngineService $engine,
    ) {
    }

    /**
     * 待排任务池（任务池拖拽数据源）
     *
     * @return array{total: int, items: list<array>}
     */
    public function taskPool(array $filters = []): array
    {
        $query = ProdTask::with(['product.defaultMold', 'mold', 'line.order:id,order_no'])
            ->where('status', ProdTask::STATUS_UNSCHEDULED)
            ->when($filters['priority'] ?? null, fn ($q, $p) => $q->where('priority', (int) $p))
            ->when($filters['product_id'] ?? null, fn ($q, $p) => $q->where('product_id', (int) $p))
            ->when($filters['keyword'] ?? null, function ($q, $kw) {
                $q->where(fn ($w) => $w->where('task_no', 'like', "%{$kw}%")
                    ->orWhereHas('product', fn ($p) => $p->where('code', 'like', "%{$kw}%")->orWhere('name', 'like', "%{$kw}%")));
            })
            ->orderByRaw('priority DESC, delivery_date IS NULL, delivery_date ASC, id ASC');

        // 任务池必须使用“实际生效工序”过滤，不能只看 route_published：
        // 已发布但没有任何工序的路线、以及历史手动建单未补工序的任务，都不能进入甘特拖拽入口。
        $candidates = $query->with(['route.ops', 'product.defaultRoute.ops'])->get();
        $items = $candidates
            ->filter(fn (ProdTask $task) => $this->poolOperations($task)->isNotEmpty())
            ->values();
        $limit = min((int) ($filters['limit'] ?? 500), 1000);

        return [
            'total' => $items->count(),
            'items' => $items->take($limit)->map(fn (ProdTask $task) => $this->poolItem($task))->all(),
        ];
    }

    /**
     * 任务池可用的生效工序：路线必须已发布，且至少有一道有类型的工序。
     * route_ops_override 优先于路线库，和正式排产引擎保持同一口径。
     */
    private function poolOperations(ProdTask $task): Collection
    {
        $route = $this->poolRoute($task);
        if (! $route) {
            return collect();
        }

        try {
            return $this->engine->effectiveRouteOps($task, $route)
                ->filter(fn (RouteOp $op) => (int) $op->seq > 0 && trim((string) $op->op_type) !== '')
                ->sortBy('seq')
                ->values();
        } catch (\Throwable) {
            return collect();
        }
    }

    /** 任务池解析路线时必须和正式排产一样优先使用任务锁定路线。 */
    private function poolRoute(ProdTask $task): ?PlmRoute
    {
        $route = $task->route_id
            ? ($task->relationLoaded('route') ? $task->getRelation('route') : $task->route)
            : ($task->product?->relationLoaded('defaultRoute') ? $task->product->getRelation('defaultRoute') : $task->product?->defaultRoute);

        if (! $route || $route->status !== PlmRoute::STATUS_PUBLISHED) {
            return null;
        }

        $route->loadMissing('ops');

        return $route;
    }

    /**
     * 任务池单条
     */
    private function poolItem(ProdTask $task): array
    {
        $ops = $this->poolOperations($task);
        $mold = $task->mold ?: $task->product->defaultMold;
        $moldStatus = $mold?->status;
        $moldUnavailable = in_array($moldStatus, [Mold::STATUS_REPAIR, Mold::STATUS_SCRAPPED], true);
        /** @var RouteOp|null $firstOp */
        // 初次拖入甘特的资源语义与 validateResourcePlacement 一致：
        // 含成型工序时按第一道成型工序决定注塑/吹塑，否则按第一道产线工序决定资源类型。
        $firstOp = $ops->first(fn (RouteOp $op) => in_array($op->op_type, ScheduleEngineService::MOLDING_OPS, true)) ?? $ops->first();
        $resourceType = $firstOp?->resourceType();
        $estimate = $this->estimateMoldingHours($task);

        return [
            'id' => $task->id,
            'task_no' => $task->task_no,
            'product_id' => $task->product_id,
            'product_code' => $task->product->code ?? '',
            'product_name' => $task->product->name ?? '',
            'qty' => $task->qty,
            'priority' => $task->priority,
            'delivery_date' => $task->delivery_date?->format('Y-m-d'),
            'is_overdue' => $task->delivery_date !== null && $task->delivery_date->isPast(),
            'mold_id' => $mold?->id,
            'mold_code' => $mold?->code ?? '',
            'mold_status' => $moldStatus,
            'mold_status_label' => $mold?->status_label,
            'mold_unavailable' => $moldUnavailable,
            'default_mold_code' => $task->product->defaultMold?->code ?? '',
            'default_route_id' => $task->route_id ?: $task->product->default_route_id,
            'route_published' => true,
            'route_ops_count' => $ops->count(),
            'required_op_type' => $firstOp?->op_type,
            'required_op_name' => $firstOp?->op_name,
            'required_resource_type' => $resourceType,
            'required_resource_label' => Machine::TYPE_LABELS[$resourceType] ?? ($resourceType ?: '未配置'),
            'estimated_hours' => $estimate['hours'],
            'estimate_ok' => $estimate['ok'],
            'order_no' => $task->line?->order?->order_no ?? '',
            'customer_name' => $task->line?->order?->customer_name ?? '',
        ];
    }

    /**
     * 成型工序预计时长（任务池"需 X 小时"即时显示）
     */
    private function estimateMoldingHours(ProdTask $task): array
    {
        try {
            $route = $this->poolRoute($task);
            if (! $route) {
                return ['ok' => false, 'hours' => null];
            }

            $route->load('ops');
            $ops = $this->engine->effectiveRouteOps($task, $route);
            $moldingOp = $ops->first(fn ($op) => in_array($op->op_type, ScheduleEngineService::MOLDING_OPS, true));
            if (! $moldingOp) {
                return ['ok' => false, 'hours' => null];
            }

            $moldingQty = collect($task->effectiveOps())
                ->firstWhere('seq', (int) $moldingOp->seq)['qty'] ?? null;
            $moldingQty = $moldingQty === null ? (int) $task->qty : max(1, (int) $moldingQty);

            $machineQuery = Machine::where('type', $moldingOp->machine_type ?: $moldingOp->resourceType())
                ->where('is_active', 1)
                ->where('status', Machine::STATUS_RUNNING);
            $mold = $task->mold ?: $task->product->defaultMold;
            if ($mold) {
                if (in_array($mold->status, [Mold::STATUS_REPAIR, Mold::STATUS_SCRAPPED], true)) {
                    return ['ok' => false, 'hours' => null];
                }
                $machineQuery->whereIn('id', $mold->machines()->pluck('machines.id'));
            }
            $requiredTonnage = $task->product?->required_tonnage;
            if ($requiredTonnage !== null) {
                $machineQuery->where(function ($query) use ($requiredTonnage) {
                    $query->whereNull('tonnage')->orWhere('tonnage', '>=', (int) $requiredTonnage);
                });
            }
            // 任务池未指定最终机台时，取当前可行机台中最快的一台作为预估值。
            $machine = $machineQuery
                ->orderByDesc('capacity_per_hour')
                ->orderBy('code')
                ->first();
            $capacity = $machine?->capacity_per_hour ?: 0;
            $moldChange = (int) ($moldingOp->mold_change_min ?? $machine?->mold_change_min ?? 0);

            if (! $capacity) {
                return ['ok' => false, 'hours' => null];
            }

            return [
                'ok' => true,
                'hours' => round(CapacityCalcService::estimateHours($moldingQty, (float) $capacity, $moldChange), 2),
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'hours' => null];
        }
    }

    /**
     * 甘特时间线数据：机台/产线行 × 时间窗（B9 双视图共用数据源）
     *
     * 行只保留机台/产线（machines），按车间（workshop.sort）→ 机台编码排序。
     *
     * @return array{start: string, end: string, shift: array, machines: list<array>, day_labels: list<string>}
     */
    public function timeline(CarbonInterface $start, CarbonInterface $end): array
    {
        // 实际报工窗口内的排程必须保留，即使计划时间后来被手工移出窗口；
        // 否则“过去 7 天”只能看到计划条，看不到真实生产记录。
        $reportedScheduleIds = WorkReport::query()
            ->whereNotNull('schedule_id')
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('report_time', [$start, $end])
                    ->orWhere(fn ($range) => $range
                        ->whereNotNull('start_time')
                        ->whereNotNull('end_time')
                        ->where('start_time', '<=', $end)
                        ->where('end_time', '>=', $start));
            })
            ->pluck('schedule_id');
        $reportedTaskIds = WorkReport::query()
            ->whereNull('schedule_id')
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('report_time', [$start, $end])
                    ->orWhere(fn ($range) => $range
                        ->whereNotNull('start_time')
                        ->whereNotNull('end_time')
                        ->where('start_time', '<=', $end)
                        ->where('end_time', '>=', $start));
            })
            ->pluck('task_id');

        $schedules = \App\Models\TaskSchedule::with(['task.product', 'task.line.order:id,order_no', 'mold'])
            ->where(function ($q) use ($start, $end, $reportedScheduleIds, $reportedTaskIds) {
                $q->whereBetween('plan_start', [$start, $end])
                    ->orWhereBetween('plan_end', [$start, $end])
                    ->orWhere(fn ($w) => $w->where('plan_start', '<=', $start)->where('plan_end', '>=', $end))
                    ->orWhere(fn ($w) => $w
                        ->whereNotNull('actual_start')
                        ->where('actual_start', '<=', $end)
                        ->where(fn ($actual) => $actual->whereNull('actual_end')->orWhere('actual_end', '>=', $start)))
                    // 计划已经结束但实际预测仍延后的任务，也必须出现在当前甘特窗口。
                    ->orWhere(fn ($w) => $w
                        // 预测时间为空时回退到对应计划边界；不能用 whereNull
                        // 直接放宽为恒真，否则所有时间窗外的存量排程都会被带入。
                        ->where(fn ($forecast) => $forecast
                            ->where(fn ($from) => $from
                                ->whereNotNull('forecast_start')
                                ->where('forecast_start', '<=', $end))
                            ->orWhere(fn ($from) => $from
                                ->whereNull('forecast_start')
                                ->where('plan_start', '<=', $end)))
                        ->where(fn ($forecast) => $forecast
                            ->where(fn ($to) => $to
                                ->whereNotNull('forecast_end')
                                ->where('forecast_end', '>=', $start))
                            ->orWhere(fn ($to) => $to
                                ->whereNull('forecast_end')
                                ->where('plan_end', '>=', $start))))
                    ->orWhereIn('id', $reportedScheduleIds)
                    ->orWhereIn('task_id', $reportedTaskIds);
            })
            ->orderBy('plan_start')
            ->get();

        // 历史排程所在的维修/停用机台仍要保留为甘特行，否则查询到了排程，
        // 但因为机台主数据被过滤，最终仍然无法展示过去的任务。
        $scheduledMachineIds = $schedules->pluck('machine_id')->filter()->unique()->values();
        $machines = Machine::where(function ($query) use ($scheduledMachineIds) {
                $query->where('is_active', 1)
                    ->orWhereIn('id', $scheduledMachineIds);
            })
            ->with('workshop:id,name,sort')
            ->get()
            ->sortBy([
                fn ($m) => (int) ($m->workshop?->sort ?? 999),
                fn ($m) => $m->code,
            ])
            ->values();

        // 甘特进度按排程段汇总报工，避免只读 task_schedules.done_qty 导致实际进度缺失或滞后。
        $reportsBySchedule = collect();
        $scheduleIds = $schedules->pluck('id');
        if ($scheduleIds->isNotEmpty()) {
            $reportsBySchedule = WorkReport::query()
                ->whereIn('schedule_id', $scheduleIds)
                ->orderByRaw('COALESCE(start_time, report_time)')
                ->get(['schedule_id', 'qty', 'report_time', 'start_time', 'end_time'])
                ->groupBy('schedule_id');
        }

        // 任务级报工没有 schedule_id，但代表该任务的成品完成量。
        // 只挂到任务最后一道工序，避免同一笔数量在每道工序上重复显示。
        $taskLevelReports = WorkReport::query()
            ->whereIn('task_id', $schedules->pluck('task_id')->unique())
            ->whereNull('schedule_id')
            ->orderByRaw('COALESCE(start_time, report_time)')
            ->get(['task_id', 'qty', 'report_time', 'start_time', 'end_time'])
            ->groupBy('task_id');
        // 任务级成品报工只能挂到任务全量排程中的最后一道未打断工序；
        // 不能按当前时间窗内的“最后一行”判断，否则末工序在窗口外时会
        // 把成品数量错误显示到窗口内的前序工序。
        $lastScheduleIds = \App\Models\TaskSchedule::whereIn('task_id', $schedules->pluck('task_id')->unique())
            ->where('is_interrupted', 0)
            ->get(['id', 'task_id', 'seq'])
            ->groupBy('task_id')
            ->map(fn ($rows) => $rows->sortBy([['seq', 'desc'], ['id', 'desc']])->first()?->id);

        $machineRows = $machines->map(function (Machine $machine) use ($schedules, $reportsBySchedule, $taskLevelReports, $lastScheduleIds) {
            $rows = $schedules->filter(fn ($s) => (int) $s->machine_id === (int) $machine->id)->values();

            return [
                'id' => $machine->id,
                'code' => $machine->code,
                'name' => $machine->name,
                'type' => $machine->type,
                'type_label' => $machine->type_label,
                'workshop_id' => $machine->workshop_id,
                'workshop_name' => $machine->workshop_name,
                'capacity_per_hour' => (float) $machine->capacity_per_hour,
                'shift_mode' => $machine->shift_mode,
                'schedules' => $rows->map(function ($s) use ($reportsBySchedule, $taskLevelReports, $lastScheduleIds) {
                    $taskReports = (int) $lastScheduleIds->get($s->task_id) === (int) $s->id
                        ? $taskLevelReports->get($s->task_id, collect())
                        : collect();

                    return $this->scheduleItem($s, $reportsBySchedule->get($s->id, collect()), $taskReports);
                })->all(),
            ];
        })->values()->all();

        // 甘特红框必须复用保存时的统一规则：严格重叠、同机台/产线切换间隔不足
        // 30 分钟，以及同模具并发都要标识，不能只判断相邻机台条的几何重叠。
        $conflictRows = $schedules->map(fn ($s) => [
            'key' => 'db:'.$s->id,
            'id' => (int) $s->id,
            'task_id' => (int) $s->task_id,
            'task_no' => $s->task?->task_no ?? '',
            'machine_id' => $s->machine_id ? (int) $s->machine_id : null,
            'mold_id' => $s->mold_id ? (int) $s->mold_id : null,
            'plan_start' => $s->plan_start,
            'plan_end' => $s->plan_end,
            'forecast_start' => $s->forecast_start,
            'forecast_end' => $s->forecast_end,
            'actual_start' => $s->actual_start,
            'actual_end' => $s->actual_end,
            'status' => $s->status,
            'task_status' => $s->task?->status,
            'is_interrupted' => (int) $s->is_interrupted,
        ])->all();
        $conflicts = $this->engine->findConflicts($conflictRows, []);
        $conflictScheduleIds = [];
        foreach ($conflicts as $conflict) {
            [$resourceType, $resourceId] = explode(':', (string) $conflict['resource'], 2);
            foreach ($schedules as $schedule) {
                $isConflictingTask = in_array((int) $schedule->task_id, [
                    (int) $conflict['task_id_a'],
                    (int) $conflict['task_id_b'],
                ], true);
                $isConflictingResource = $resourceType === 'machine'
                    ? (int) $schedule->machine_id === (int) $resourceId
                    : (int) $schedule->mold_id === (int) $resourceId;
                if ($isConflictingTask && $isConflictingResource) {
                    $conflictScheduleIds[(int) $schedule->id] = true;
                }
            }
        }

        $mark = function (&$row) use ($conflictScheduleIds): void {
            $row['is_conflict'] = isset($conflictScheduleIds[$row['id']]) ? 1 : 0;
        };
        foreach ($machineRows as &$m) {
            foreach ($m['schedules'] as &$s) {
                $mark($s);
            }
            unset($s);
        }
        unset($m);

        return [
            'start' => $start->format('Y-m-d 00:00:00'),
            'end' => $end->format('Y-m-d 23:59:59'),
            'shift' => [
                'two_shift' => ConfigService::shiftPeriods('two_shift'),
                'day' => ConfigService::shiftPeriods('day'),
            ],
            'machines' => $machineRows,
            'day_labels' => $this->dayLabels($start, $end),
        ];
    }

    /**
     * 任务排程明细（甘特 + 明细双视图：机台/起止/状态/偏差）
     */
    public function detail(int $taskId): ?array
    {
        $task = ProdTask::with(['product', 'mold', 'line.order:id,order_no', 'schedules.machine.workshop'])
            ->find($taskId);

        if (! $task) {
            return null;
        }

        return $this->formatDetail($task);
    }

    /**
     * 已排产任务明细列表（默认全部，支持按工单号筛选）
     *
     * @return array{total: int, items: list<array>}
     */
    public function details(array $filters = []): array
    {
        $query = ProdTask::with(['product', 'mold', 'line.order:id,order_no', 'schedules.machine.workshop'])
            ->whereHas('schedules')
            ->when($filters['keyword'] ?? null, fn ($q, $keyword) => $q->where('task_no', 'like', '%'.$keyword.'%'))
            // 日期筛选只看计划时间：当天与任一工序计划区间相交即可，实际报工时间不参与筛选。
            ->when($filters['date'] ?? null, function ($q, $date) {
                $q->whereHas('schedules', fn ($schedule) => $schedule
                    ->where('plan_start', '<=', $date.' 23:59:59')
                    ->where('plan_end', '>=', $date.' 00:00:00'));
            })
            ->when($filters['order_no'] ?? null, fn ($q, $orderNo) => $q->whereHas('line.order', fn ($order) => $order->where('order_no', 'like', '%'.$orderNo.'%')))
            ->when($filters['product'] ?? null, fn ($q, $product) => $q->whereHas('product', fn ($item) => $item
                ->where('code', 'like', '%'.$product.'%')
                ->orWhere('name', 'like', '%'.$product.'%')))
            ->when($filters['machine'] ?? null, fn ($q, $machine) => $q->whereHas('schedules.machine', fn ($item) => $item
                ->where('code', 'like', '%'.$machine.'%')
                ->orWhere('name', 'like', '%'.$machine.'%')))
            ->orderByRaw('plan_start IS NULL, plan_start ASC')
            ->orderBy('id');

        $total = (clone $query)->count();
        $limit = min(max((int) ($filters['limit'] ?? 500), 1), 1000);
        $date = $filters['date'] ?? null;
        $items = $query->limit($limit)->get()->map(function (ProdTask $task) use ($date) {
            // 列表只展示汇总，避免一次加载所有任务的报工流水；单任务明细再按工序展开日志。
            $detail = $this->formatDetail($task, false);
            if ($date) {
                $detail['schedules'] = array_values(array_filter(
                    $detail['schedules'],
                    fn (array $schedule) => $schedule['plan_start'] <= $date.' 23:59:59'
                        && $schedule['plan_end'] >= $date.' 00:00:00',
                ));
            }

            return $detail;
        })->all();

        return compact('total', 'items');
    }

    /**
     * 统一构造任务排程明细，保证单任务和批量列表使用完全相同的报工/进度口径。
     */
    private function formatDetail(ProdTask $task, bool $includeReportLogs = true): array
    {

        $scheduleIds = $task->schedules->pluck('id');
        $reportQuery = WorkReport::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->orderByRaw('COALESCE(start_time, report_time)')
            ->when($includeReportLogs, fn ($query) => $query->with([
                'operator:id,name,employee_no',
                'worker:id,name,employee_no',
                'machine:id,code,name',
            ]));
        $reportsBySchedule = $reportQuery
            ->get(['id', 'schedule_id', 'qty', 'defect_qty', 'sign', 'report_by', 'worker_id', 'machine_id', 'report_time', 'start_time', 'end_time', 'work_date', 'shift_code', 'source', 'remark'])
            ->groupBy('schedule_id');
        $taskReportQuery = WorkReport::query()
            ->where('task_id', $task->id)
            ->whereNull('schedule_id')
            ->orderByRaw('COALESCE(start_time, report_time)')
            ->when($includeReportLogs, fn ($query) => $query->with([
                'operator:id,name,employee_no',
                'worker:id,name,employee_no',
                'machine:id,code,name',
            ]));
        $taskLevelReports = $taskReportQuery
            ->get(['id', 'task_id', 'qty', 'defect_qty', 'sign', 'report_by', 'worker_id', 'machine_id', 'report_time', 'start_time', 'end_time', 'work_date', 'shift_code', 'source', 'remark']);
        // 明细展示按实际事实纠正历史残留状态：仅有排程、没有报工/实际起止时，
        // 任务主表即使残留 in_progress，也只能显示为“已排产”，不能误导为已开工。
        $hasActualTaskProgress = (int) $task->done_qty > 0
            || $taskLevelReports->isNotEmpty()
            || $task->schedules->contains(function ($schedule) {
                return in_array($schedule->status, [
                    \App\Models\TaskSchedule::STATUS_RUNNING,
                    \App\Models\TaskSchedule::STATUS_DONE,
                ], true)
                    || $schedule->done_qty > 0
                    || $schedule->actual_start !== null
                    || $schedule->actual_end !== null;
            });
        $displayStatus = $task->status;
        if ($displayStatus === ProdTask::STATUS_IN_PROGRESS && ! $hasActualTaskProgress && $task->schedules->isNotEmpty()) {
            $displayStatus = ProdTask::STATUS_SCHEDULED;
        }
        $lastScheduleId = $task->schedules
            ->where('is_interrupted', 0)
            ->sortBy([['seq', 'desc'], ['id', 'desc']])
            ->first()?->id;

        return [
            'task' => [
                'id' => $task->id,
                'task_no' => $task->task_no,
                'product_id' => $task->product_id,
                'product_code' => $task->product->code ?? '',
                'product_name' => $task->product->name ?? '',
                'qty' => $task->qty,
                'done_qty' => $task->done_qty,
                'status' => $displayStatus,
                'status_label' => ProdTask::STATUS_LABELS[$displayStatus] ?? $displayStatus,
                'priority' => $task->priority,
                'delivery_date' => $task->delivery_date?->format('Y-m-d'),
                'plan_start' => $task->plan_start?->format('Y-m-d H:i:s'),
                'plan_end' => $task->plan_end?->format('Y-m-d H:i:s'),
                'deviation_pct' => (float) $task->deviation_pct,
                'is_locked' => (int) $task->is_locked,
                'mold_code' => $task->mold?->code ?? '',
                'order_no' => $task->line?->order?->order_no ?? '',
            ],
            'schedules' => $task->schedules->map(function ($s) use ($reportsBySchedule, $taskLevelReports, $lastScheduleId, $includeReportLogs) {
                $scheduleReports = $reportsBySchedule->get($s->id, collect());
                if ((int) $s->id === (int) $lastScheduleId && (int) $s->is_interrupted === 0) {
                    $scheduleReports = $scheduleReports->concat($taskLevelReports);
                }
                // qty 已经带正负号：正常报工为正，冲减为负，不能再次乘 sign。
                $reportedQty = max(0, (int) $scheduleReports->sum('qty'));
                $positiveReports = $scheduleReports->filter(fn ($report) => (int) $report->qty > 0)->values();
                $actualStart = $s->actual_start ?? $this->reportStart($positiveReports->first());
                $actualEnd = $s->actual_end;
                if ($actualEnd === null && $reportedQty >= (int) $s->qty && $positiveReports->isNotEmpty()) {
                    $actualEnd = $this->reportEnd($positiveReports->last());
                }
                $status = $s->status;
                if ($reportedQty >= (int) $s->qty && (int) $s->qty > 0) {
                    $status = \App\Models\TaskSchedule::STATUS_DONE;
                } elseif ($reportedQty > 0 && $status === \App\Models\TaskSchedule::STATUS_PENDING) {
                    $status = \App\Models\TaskSchedule::STATUS_RUNNING;
                }

                return [
                    'id' => $s->id,
                    'seq' => $s->seq,
                    'op_type' => $s->op_type,
                    'op_name' => $s->op_name ?? $s->op_type,
                    'resource_type' => \App\Models\RouteOp::resourceTypeFor($s->op_type),
                    'machine_id' => $s->machine_id,
                    'machine_code' => $s->machine?->code ?? null,
                    'machine_name' => $s->machine?->name ?? null,
                    'workshop_id' => $s->machine?->workshop_id,
                    'workshop_name' => $s->machine?->workshop?->name,
                    'mold_id' => $s->mold_id,
                    'plan_start' => $s->plan_start->format('Y-m-d H:i:s'),
                    'plan_end' => $s->plan_end->format('Y-m-d H:i:s'),
                    'qty' => $s->qty,
                    'status' => $status,
                    'source' => $s->source,
                    'is_interrupted' => (int) $s->is_interrupted,
                    'done_qty' => (int) $s->done_qty,
                    'reported_qty' => $reportedQty,
                    'progress_pct' => (int) min(100, round($s->qty > 0 ? $reportedQty / $s->qty * 100 : 0)),
                    'is_overlap' => (int) $s->is_overlap,
                    'actual_start' => $actualStart?->format('Y-m-d H:i:s'),
                    'actual_end' => $actualEnd?->format('Y-m-d H:i:s'),
                    'actual_segments' => $this->actualSegments($scheduleReports, $s->machine?->shift_mode ?: 'day'),
                    'report_logs' => $includeReportLogs ? $this->reportLogs($scheduleReports, $s->machine?->shift_mode ?: 'day') : [],
                    // 冻结区随当前时间滚动计算，避免任务从窗口外进入未来三天后仍可移动。
                    'is_frozen' => $this->isInFreezeWindow($s),
                    'changeover_minutes' => (int) $s->changeover_minutes,
                    'note' => $s->note,
                ];
            })->all(),
        ];
    }

    /**
     * 时间线单条排程（甘特条）
     */
    private function scheduleItem(
        \App\Models\TaskSchedule $s,
        Collection $scheduleReports = new Collection(),
        Collection $taskLevelReports = new Collection(),
    ): array
    {
        $scheduleReports = $scheduleReports->concat($taskLevelReports);
        // qty 已带正负号：正常报工为正，冲减为负；进度使用净报工量并限制在 0~100%。
        $reportedQty = max(0, (int) $scheduleReports->sum('qty'));
        $positiveReports = $scheduleReports->filter(fn ($report) => (int) $report->qty > 0)->values();
        $actualStart = $s->actual_start ?? $this->reportStart($positiveReports->first());
        $actualEnd = $s->actual_end;
        if ($actualEnd === null && $reportedQty >= (int) $s->qty && $positiveReports->isNotEmpty()) {
            $actualEnd = $this->reportEnd($positiveReports->last());
        }
        $status = $s->status;
        if ($reportedQty >= (int) $s->qty && (int) $s->qty > 0) {
            $status = \App\Models\TaskSchedule::STATUS_DONE;
        } elseif ($reportedQty > 0 && $status === \App\Models\TaskSchedule::STATUS_PENDING) {
            $status = \App\Models\TaskSchedule::STATUS_RUNNING;
        }

        return [
            'id' => $s->id,
            'task_id' => $s->task_id,
            'task_no' => $s->task?->task_no ?? '',
            'product_code' => $s->task?->product?->code ?? '',
            'product_name' => $s->task?->product?->name ?? '',
            'order_no' => $s->task?->line?->order?->order_no ?? '',
            'op_type' => $s->op_type,
            'op_name' => $s->op_name ?? $s->op_type,
            'resource_type' => \App\Models\RouteOp::resourceTypeFor($s->op_type),
            'seq' => $s->seq,
            'machine_id' => $s->machine_id,
            'mold_id' => $s->mold_id,
            'mold_code' => $s->mold?->code ?? '',
            'qty' => $s->qty,
            'done_qty' => $s->done_qty,
            'reported_qty' => $reportedQty,
            'progress_pct' => (int) min(100, round($s->qty > 0 ? $reportedQty / $s->qty * 100 : 0)),
            'status' => $status,
            'source' => $s->source,
            'is_locked' => (int) ($s->task?->is_locked ?? 0),
            'is_overlap' => (int) $s->is_overlap,
            'is_interrupted' => (int) $s->is_interrupted,
            'note' => $s->note,
            'plan_start' => $s->plan_start->format('Y-m-d H:i:s'),
            'plan_end' => $s->plan_end->format('Y-m-d H:i:s'),
            'forecast_start' => $s->forecast_start?->format('Y-m-d H:i:s'),
            'forecast_end' => $s->forecast_end?->format('Y-m-d H:i:s'),
            'actual_start' => $actualStart?->format('Y-m-d H:i:s'),
            'actual_end' => $actualEnd?->format('Y-m-d H:i:s'),
            'actual_segments' => $this->actualSegments($scheduleReports, $s->machine?->shift_mode ?: 'day'),
            // 冻结区随当前时间滚动计算，数据库标记只作为写入留痕，不作为过期的显示依据。
            'is_frozen' => $this->isInFreezeWindow($s),
            'changeover_minutes' => (int) $s->changeover_minutes,
            'is_conflict' => 0,
        ];
    }

    /**
     * 把每条正报工转换为甘特实际生产段；没有流水的日期不会被补成连续区间。
     */
    private function actualSegments(Collection $reports, string $shiftMode): array
    {
        $segments = [];
        foreach ($reports->filter(fn ($report) => (int) $report->qty > 0)->sortBy(fn ($report) => $this->reportStart($report)?->timestamp ?? 0) as $report) {
            $pieces = $this->workingSegments($this->reportStart($report), $this->reportEnd($report), $shiftMode);
            foreach ($pieces as $index => $piece) {
                $segments[] = [
                    'start' => $piece['start'],
                    'end' => $piece['end'],
                    // 每条报工现在最多只有一个实际段，数量只归到该段。
                    'qty' => $index === 0 ? (int) $report->qty : 0,
                ];
            }
        }

        return $segments;
    }

    /**
     * 明细弹窗的工序级报工日志。任务级报工在 formatDetail 中已归并到末道工序，
     * 因此这里直接输出当前工序实际包含的全部正报工和冲减记录。
     */
    private function reportLogs(Collection $reports, string $shiftMode): array
    {
        return $reports
            ->sortBy(fn ($report) => $this->reportStart($report)?->timestamp ?? 0)
            ->map(fn ($report) => [
                'id' => (int) $report->id,
                'qty' => (int) $report->qty,
                'defect_qty' => (int) $report->defect_qty,
                'sign' => (int) $report->sign,
                'report_time' => $report->report_time?->format('Y-m-d H:i:s'),
                'start_time' => $report->start_time?->format('Y-m-d H:i:s'),
                'end_time' => $report->end_time?->format('Y-m-d H:i:s'),
                'work_segments' => $this->workingSegments($this->reportStart($report), $this->reportEnd($report), $shiftMode),
                'work_date' => $report->work_date?->format('Y-m-d'),
                'shift_code' => $report->shift_code,
                'source' => $report->source,
                'source_label' => $report->source_label,
                'operator_name' => $report->operator?->name,
                'worker_name' => $report->worker?->name,
                'machine_code' => $report->machine?->code,
                'remark' => $report->remark,
            ])
            ->values()
            ->all();
    }

    /**
     * 把一条报工归一为一个资源实际有效作业段。
     * 历史白班跨天数据已由迁移归一化；这里最多返回一个片段，避免一条记录在界面上被拆成两段。
     */
    private function workingSegments(?Carbon $start, ?Carbon $end, string $shiftMode): array
    {
        if ($start === null || $end === null) {
            return [];
        }
        if ($start->equalTo($end) || $shiftMode === 'two_shift') {
            return [[
                'start' => $start->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ]];
        }

        $period = ConfigService::shiftPeriods('day');
        $cursor = $start->copy();
        for ($guard = 0; $guard < 10000 && $cursor->lt($end); $guard++) {
            [$windowStart, $windowEnd] = $this->shiftWindow($cursor, $period);
            if ($cursor->lt($windowStart)) {
                $cursor = $windowStart;
                continue;
            }
            if ($cursor->gte($windowEnd)) {
                $cursor = $windowStart->copy()->addDay()->setTimeFromTimeString((string) $period['start']);
                continue;
            }

            $segmentEnd = $end->lt($windowEnd) ? $end->copy() : $windowEnd;
            return [[
                'start' => $cursor->format('Y-m-d H:i:s'),
                'end' => $segmentEnd->format('Y-m-d H:i:s'),
            ]];
        }

        return [];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function shiftWindow(Carbon $cursor, array $period): array
    {
        $dayStart = $cursor->copy()->startOfDay();

        return [
            $dayStart->copy()->setTimeFromTimeString((string) ($period['start'] ?? '08:00')),
            $dayStart->copy()->setTimeFromTimeString((string) ($period['end'] ?? '17:00')),
        ];
    }

    private function reportStart($report): ?Carbon
    {
        return $report?->start_time ?? $report?->report_time;
    }

    private function reportEnd($report): ?Carbon
    {
        return $report?->end_time ?? $report?->report_time;
    }

    /**
     * 甘特图当前可执行冻结区：未完工且计划开始时间不晚于当前时间 + N 天。
     */
    private function isInFreezeWindow(\App\Models\TaskSchedule $schedule): int
    {
        if ($schedule->status === \App\Models\TaskSchedule::STATUS_DONE || $schedule->plan_start === null) {
            return 0;
        }

        $now = Carbon::now();
        $freezeUntil = $now->copy()->addDays((int) config('aps.freeze_days', 3));

        return $schedule->plan_start->gte($now) && $schedule->plan_start->lte($freezeUntil) ? 1 : 0;
    }

    /**
     * 时间窗逐日标签
     */
    private function dayLabels(CarbonInterface $start, CarbonInterface $end): array
    {
        $labels = [];
        $cursor = Carbon::parse($start)->startOfDay();
        $last = Carbon::parse($end)->startOfDay();

        while ($cursor->lte($last)) {
            $labels[] = $cursor->format('m-d');
            $cursor->addDay();
        }

        return $labels;
    }
}
