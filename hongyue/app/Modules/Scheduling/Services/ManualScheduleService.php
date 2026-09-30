<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\RouteOp;
use App\Models\ScheduleVersion;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Order\Services\OrderService;
use App\Modules\Plm\Services\CapacityCalcService;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use App\Modules\System\Services\ConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 手工排产服务（B2/B3/B7/B8）
 *
 * 保存流程（事务内）：
 * 1. 逐任务三匹配校验（产品-模具-机台，force 不豁免）
 * 2. 产能+班制+换模自动计算结束时间（工序链）
 * 3. 冲突检测：有冲突且未 force → 409 附冲突清单；force → 标记 is_overlap 显式确认
 * 4. 重写受影响任务排程 + 任务状态（scheduled/plan/锁定）
 * 5. 写版本快照（回滚支撑）→ 订单状态聚合 → 审计
 */
class ManualScheduleService
{
    /** 排程模式 */
    public const MODE_FORWARD = 'forward';
    public const MODE_BACKWARD = 'backward';

    public function __construct(
        private readonly ScheduleEngineService $engine,
        private readonly VersionService $versionService,
        private readonly OrderService $orderService,
        private readonly BackwardScheduleService $backwardService,
        private readonly SuggestionService $suggestionService,
    ) {
    }

    /**
     * 手工排程保存（支持批量）
     *
     * @param  list<array>  $items  [['task_id'=>, 'machine_id'=>, 'plan_start'=>, 'mold_id'=>?, 'deadline'=>?], ...]
     * @param  bool  $force  强制排入（允许时间重叠，标记 is_overlap）
     * @param  string  $mode  forward=顺排（默认，起点取 plan_start）/ backward=倒排（交期倒推，不可行降级顺排）
     * @return array{version: ScheduleVersion, tasks: array, conflicts: array, backward_downgraded: list<string>}
     *
     * @throws ScheduleException
     */
    public function save(array $items, int $userId, string $source = TaskSchedule::SOURCE_MANUAL, bool $force = false, ?string $note = null, ?string $bizKey = null, string $mode = self::MODE_FORWARD): array
    {
        if ($items === []) {
            throw new ScheduleException('排程保存项为空', [['code' => 'empty_items', 'message' => '排程保存项为空']]);
        }

        // 防重（QA P1）：同一请求内同一 task_id 重复出现 → 拒绝，避免工序链双写/产能双算
        $rawTaskIds = array_map(fn ($item) => (int) ($item['task_id'] ?? 0), $items);
        $duplicates = array_values(array_unique(array_diff_assoc($rawTaskIds, array_unique($rawTaskIds))));
        if ($duplicates !== []) {
            throw new ScheduleException('同一任务在一次请求中重复出现，请检查提交内容', [
                ['code' => 'duplicate_task_in_batch', 'message' => '同一任务在一次请求中重复出现', 'task_ids' => $duplicates],
            ]);
        }

        return DB::transaction(function () use ($items, $userId, $source, $force, $note, $bizKey, $mode) {
            $beforeRows = $this->engine->loadWorkRows();
            // 批量重排时，受影响任务的旧工序链会在本次事务中整体替换；
            // 旧链不能继续作为其它任务占用，否则共享模具的后一个任务会被
            // “自己的旧排程”挡住，保存阶段又把已在算法后处理中顺延好的结果判冲突。
            // 完整 beforeRows 仍用于版本快照，只有排程计算工作副本排除受影响任务。
            $affectedInputTaskIds = array_values(array_unique(array_map(
                fn ($item): int => (int) ($item['task_id'] ?? 0),
                $items,
            )));
            $beforeWorkRows = array_values(array_filter(
                $beforeRows,
                fn (array $row): bool => ! in_array((int) ($row['task_id'] ?? 0), $affectedInputTaskIds, true),
            ));
            $newRows = [];
            $affectedTaskIds = [];
            $taskInfos = [];
            $backwardDowngraded = [];

            foreach ($items as $item) {
                $task = ProdTask::with('product', 'line:id,order_id')->find((int) ($item['task_id'] ?? 0));

                if (! $task) {
                    throw new ScheduleException('任务不存在', [['code' => 'task_not_found', 'message' => '任务不存在', 'task_id' => $item['task_id'] ?? null]], 404);
                }
                if ($task->is_locked) {
                    throw new ScheduleException("任务 {$task->task_no} 已锁定（保存即锁定），请先解锁再重排", [
                        ['code' => 'task_locked', 'message' => '任务已锁定，请先解锁', 'task_id' => $task->id, 'task_no' => $task->task_no],
                    ]);
                }
                $hasActualProgress = $task->schedules()
                    ->where(function ($query) {
                        $query->whereIn('status', [TaskSchedule::STATUS_RUNNING, TaskSchedule::STATUS_DONE])
                            ->orWhereNotNull('actual_start')
                            ->orWhereNotNull('actual_end');
                    })
                    ->exists();
                $hasReportLedger = WorkReport::where('task_id', $task->id)->exists();
                // 任务主表状态可能因历史数据/旧流程残留为 in_progress，不能单独作为“已经生产”的证据。
                // 只有工序实际起止、工序状态、实际完工量或报工流水存在时，才保护现场事实；已完成任务仍禁止覆盖。
                if ($task->status === ProdTask::STATUS_DONE || $hasActualProgress || $hasReportLedger) {
                    throw new ScheduleException("任务 {$task->task_no} 生产中/已完成，不可重排", [
                        ['code' => 'task_not_editable', 'message' => '生产中/已完成或已有实际进度/报工流水的任务不可重排', 'task_id' => $task->id, 'task_no' => $task->task_no],
                    ]);
                }

                // 工艺确认 gate：工单尚在「工艺确认」阶段不得排产（订单-工艺-排产流程规范）
                if ($task->line?->order?->status === \App\Models\Order::STATUS_ROUTE_CONFIRM) {
                    throw new ScheduleException("任务 {$task->task_no} 所属工单尚在「工艺确认」阶段，请先确认工艺再排产", [
                        ['code' => 'order_route_not_confirmed', 'message' => '请先完成工艺确认（工单中心 → 确认工艺）', 'task_id' => $task->id, 'task_no' => $task->task_no],
                    ]);
                }

                $machineId = (int) ($item['machine_id'] ?? 0);
                $moldId = $this->engine->validateMatching($task, $machineId, isset($item['mold_id']) ? (int) $item['mold_id'] : null);

                $start = Carbon::parse($item['plan_start'] ?? now());

                // 倒排（APS一期）：从交期倒推首工序最晚可行起点；不可行自动降级顺排（B4 顺延语义之外的新排程方向）
                if ($mode === self::MODE_BACKWARD && config('aps.backward_enabled', true)) {
                    $deadline = isset($item['deadline'])
                        ? Carbon::parse($item['deadline'])->endOfDay()
                        : ($task->delivery_date ? Carbon::parse((string) $task->delivery_date)->endOfDay() : null);

                    if ($deadline === null) {
                        throw new ScheduleException("任务 {$task->task_no} 无交期，无法倒排", [
                            ['code' => 'no_deadline_for_backward', 'message' => '倒排需要交期（任务交期或显式 deadline 参数）', 'task_id' => $task->id, 'task_no' => $task->task_no],
                        ]);
                    }

                    $latest = $this->backwardService->latestStart(
                        $task,
                        $machineId,
                        $deadline,
                        Carbon::now(),
                        $moldId > 0 ? $moldId : null,
                    );
                    if ($latest === null) {
                        // 交期倒推早于当前时刻 → 降级顺排（沿用请求给定起点），记录供前端提示
                        $backwardDowngraded[] = $task->task_no;
                    } else {
                        $start = $latest;
                    }
                }

                // 批量保存时，前序任务尚未落库；把本次已构建的行带入，
                // 让后续任务也能正确解析换模矩阵的 from 模具。
                // 后续工序需要参考存量排程和本批次已构建工序，在对应资源的空档中落位；
                // 首道工序仍以本次拖入的时间为锚点。
                $chain = $this->engine->buildChain(
                    $task,
                    $machineId,
                    $moldId,
                    $start,
                    $source,
                    array_merge($beforeWorkRows, $newRows),
                );

                if ($note !== null) {
                    foreach ($chain as &$row) {
                        $row['note'] = $note;
                    }
                    unset($row);
                }

                $newRows = array_merge($newRows, $chain);
                $affectedTaskIds[] = (int) $task->id;
                $taskInfos[(int) $task->id] = ['task' => $task, 'mold_id' => $moldId, 'machine_id' => $machineId];
            }

            // 冲突检测（新行间 + 新行 vs 存量）
            $conflicts = $this->engine->findConflicts($newRows, $beforeWorkRows);

            if ($conflicts !== [] && ! $force) {
                throw new ScheduleException('排程存在资源冲突（同机台/模具时间重叠）', $conflicts);
            }

            // force：标记重叠行
            if ($force && $conflicts !== []) {
                foreach ($newRows as &$row) {
                    $row['is_overlap'] = $this->rowInConflicts($row, $conflicts) ? 1 : 0;
                    if ($row['is_overlap'] && $note === null) {
                        $row['note'] = '强制排入（存在时间重叠，已显式确认）';
                    }
                }
                unset($row);
            }

            // 重写受影响任务排程 + 任务状态
            $this->rewriteTasks($newRows, $taskInfos, $source, $force);

            $afterRows = $this->engine->loadWorkRows();
            $version = $this->versionService->snapshot(
                $userId,
                $source === TaskSchedule::SOURCE_AI ? ScheduleVersion::TYPE_AI : ScheduleVersion::TYPE_MANUAL,
                $beforeRows,
                $afterRows,
                [],
                $bizKey ?? 'manual:'.implode(',', $affectedTaskIds),
            );

            // 订单状态聚合
            $orderIds = array_values(array_unique(array_filter(array_map(fn ($info) => (int) ($info['task']->line->order_id ?? 0), $taskInfos))));
            $this->syncOrders($orderIds);
            $this->suggestionService->refreshForTasks($affectedTaskIds);

            return [
                'version' => $version->load('creator:id,name,employee_no'),
                'tasks' => ProdTask::whereIn('id', $affectedTaskIds)->with('schedules')->get(),
                'conflicts' => $conflicts,
                'backward_downgraded' => $backwardDowngraded,
            ];
        });
    }

    /**
     * 单道工序时间/资源调整。
     *
     * 甘特图横向移动已排工序时，只重算当前排程行，任务其他工序保持原计划；
     * 这样允许后工序在前工序尚未计划完工时提前排队。实际报工仍由报工服务
     * 校验前序工序的实际合格数量，计划提前不等于现场可以越过前序报工。
     *
     * @return array{version: ScheduleVersion, task: ProdTask, schedule: TaskSchedule, conflicts: list<array>}
     */
    public function adjustOperation(
        int $scheduleId,
        int $machineId,
        string $planStart,
        int $userId,
        ?string $note = null,
        ?string $planEnd = null,
        ?string $edge = null,
    ): array
    {
        return DB::transaction(function () use ($scheduleId, $machineId, $planStart, $userId, $note, $planEnd, $edge) {
            $beforeRows = $this->engine->loadWorkRows();
            $schedule = TaskSchedule::with(['task.product', 'task.line'])->find($scheduleId);

            if (! $schedule || ! $schedule->task) {
                throw new ScheduleException('工序排程不存在，无法调整', [[
                    'code' => 'schedule_not_found',
                    'message' => '工序排程不存在或已被删除',
                    'schedule_id' => $scheduleId,
                ]], 404);
            }

            $task = $schedule->task;
            if ($task->is_locked) {
                throw new ScheduleException("任务 {$task->task_no} 已锁定，请先解锁再调整", [[
                    'code' => 'task_locked',
                    'message' => '任务已锁定，请先解锁',
                    'task_id' => $task->id,
                    'task_no' => $task->task_no,
                ]]);
            }

            $hasActualProgress = TaskSchedule::where('task_id', $task->id)
                ->where(function ($query) {
                    $query->whereIn('status', [TaskSchedule::STATUS_RUNNING, TaskSchedule::STATUS_DONE])
                        ->orWhereNotNull('actual_start')
                        ->orWhereNotNull('actual_end');
                })
                ->exists();
            $hasReportLedger = WorkReport::where('task_id', $task->id)->exists();
            if ($task->status === ProdTask::STATUS_DONE || $hasActualProgress || $hasReportLedger) {
                throw new ScheduleException("任务 {$task->task_no} 已有实际生产事实，不可调整", [[
                    'code' => 'task_not_editable',
                    'message' => '任务已有实际起止、实际状态或报工流水，不可通过甘特图调整',
                    'task_id' => $task->id,
                    'task_no' => $task->task_no,
                ]]);
            }

            $route = $this->engine->resolveRoute($task);
            $ops = $this->engine->effectiveRouteOps($task, $route);
            /** @var RouteOp|null $op */
            $op = $ops->first(fn (RouteOp $item) => (int) $item->seq === (int) $schedule->seq);
            if (! $op) {
                throw new ScheduleException("任务 {$task->task_no} 的第 {$schedule->seq} 道工序不存在，无法调整", [[
                    'code' => 'operation_not_found',
                    'message' => '工序不在当前生效工艺路线中',
                    'task_id' => $task->id,
                    'task_no' => $task->task_no,
                    'seq' => (int) $schedule->seq,
                ]]);
            }

            $machine = Machine::find($machineId);
            if (! $machine) {
                throw new ScheduleException('目标资源不存在，无法调整工序', [[
                    'code' => 'machine_not_found',
                    'message' => '目标机台/产线不存在',
                    'task_id' => $task->id,
                    'task_no' => $task->task_no,
                    'op_name' => $op->op_name,
                    'machine_id' => $machineId,
                ]], 404);
            }

            $this->engine->validateOperationPlacement($task, $op, $machine, $route, $ops);

            $moldId = null;
            if ($op->isMoldingOp()) {
                $moldId = $this->engine->resolveMold($task, $schedule->mold_id ? (int) $schedule->mold_id : null);
                // 成型工序换机仍必须通过完整的产品-模具-机台三匹配。
                $this->engine->validateMatching($task, $machine->id, $moldId);
            }

            $shiftMode = $machine->shift_mode ?: ($op->shift_mode ?: 'day');
            $periods = ConfigService::shiftPeriods($shiftMode);
            $capacity = (float) ($machine->capacity_per_hour ?: ScheduleEngineService::LINE_DEFAULT_CAPACITY);
            $moldChange = $op->isMoldingOp() ? (int) ($schedule->changeover_minutes ?? 0) : 0;
            $start = $this->engine->effectiveStart(Carbon::parse($planStart), $shiftMode, $periods);
            $end = null;
            if ($edge === 'start' && $schedule->plan_end) {
                // 左侧手柄只改开始边界，原结束时间保持不变。
                $end = $schedule->plan_end->copy();
            } elseif ($edge === 'end' && $schedule->plan_start && $planEnd !== null) {
                // 右侧手柄只改结束边界，原开始时间保持不变。
                $start = $schedule->plan_start->copy();
                $end = Carbon::parse($planEnd);
            } elseif ($planEnd !== null) {
                // 明确传入完整时间范围时，按调用方给出的边界保存。
                $end = Carbon::parse($planEnd);
            }
            if (! $end) {
                $end = CapacityCalcService::estimateEndTime(
                    $start,
                    (int) $schedule->qty,
                    $capacity,
                    $moldChange,
                    $shiftMode,
                    $periods,
                );
            }
            if ($end->lte($start)) {
                throw new ScheduleException('当前工序结束时间必须晚于开始时间', [[
                    'code' => 'invalid_time_range',
                    'message' => '调整后的工序结束时间必须晚于开始时间，当前工序未移动',
                    'task_id' => $task->id,
                    'task_no' => $task->task_no,
                    'op_name' => $op->op_name,
                    'seq' => (int) $schedule->seq,
                ]], 422);
            }

            $candidate = collect($beforeRows)->first(fn (array $row) => (int) ($row['id'] ?? 0) === $scheduleId);
            if (! $candidate) {
                throw new ScheduleException('工序排程数据已变化，请刷新后重试', [[
                    'code' => 'schedule_stale',
                    'message' => '工序排程数据已变化，请刷新甘特图后重试',
                    'schedule_id' => $scheduleId,
                ]]);
            }
            $candidate['machine_id'] = $machine->id;
            $candidate['plan_start'] = $start;
            $candidate['plan_end'] = $end;
            $candidate['forecast_start'] = $start;
            $candidate['forecast_end'] = $end;
            $candidate['source'] = TaskSchedule::SOURCE_MANUAL;
            $candidate['is_overlap'] = 0;
            $candidate['is_frozen'] = 0;

            $otherRows = array_values(array_filter($beforeRows, fn (array $row) => (int) ($row['id'] ?? 0) !== $scheduleId));
            $conflicts = $this->engine->findConflicts([$candidate], $otherRows);
            if ($conflicts !== []) {
                throw new ScheduleException('当前工序调整后与其他任务存在资源冲突', $conflicts);
            }

            $now = Carbon::now();
            $freezeUntil = $now->copy()->addDays((int) config('aps.freeze_days', 3));
            $schedule->update([
                'machine_id' => $machine->id,
                'plan_start' => $start->format('Y-m-d H:i:s'),
                'plan_end' => $end->format('Y-m-d H:i:s'),
                'forecast_start' => $start->format('Y-m-d H:i:s'),
                'forecast_end' => $end->format('Y-m-d H:i:s'),
                'source' => TaskSchedule::SOURCE_MANUAL,
                'note' => $note ?? $schedule->note,
                'is_overlap' => 0,
                'is_frozen' => $start->gte($now) && $start->lte($freezeUntil) ? 1 : 0,
            ]);

            $ownSchedules = TaskSchedule::where('task_id', $task->id)->get();
            $firstSchedule = $ownSchedules->sortBy('plan_start')->first();
            $lastSchedule = $ownSchedules->sortByDesc('plan_end')->first();
            $task->update([
                'status' => ProdTask::STATUS_SCHEDULED,
                'plan_start' => $firstSchedule?->plan_start?->format('Y-m-d H:i:s'),
                'plan_end' => $lastSchedule?->plan_end?->format('Y-m-d H:i:s'),
                'is_locked' => 1,
            ]);

            $afterRows = $this->engine->loadWorkRows();
            $version = $this->versionService->snapshot(
                $userId,
                ScheduleVersion::TYPE_MANUAL,
                $beforeRows,
                $afterRows,
                [],
                'manual-operation:'.$scheduleId,
            );

            $orderId = (int) ($task->line->order_id ?? 0);
            if ($orderId > 0 && ($order = Order::find($orderId))) {
                $this->orderService->syncStatus($order);
            }
            $this->suggestionService->refreshForTasks([$task->id]);

            return [
                'version' => $version->load('creator:id,name,employee_no'),
                'task' => $task->fresh()->load('schedules'),
                'schedule' => $schedule->fresh()->load('machine'),
                'conflicts' => [],
            ];
        });
    }

    /**
     * 重写受影响任务的全部排程与任务状态
     *
     * @param  list<array>  $newRows  新排程工作副本
     * @param  array  $taskInfos  [taskId => ['task'=>ProdTask, 'mold_id'=>int, 'machine_id'=>int]]
     */
    private function rewriteTasks(array $newRows, array $taskInfos, string $source, bool $force): void
    {
        $taskIds = array_keys($taskInfos);

        // 删除旧排程
        TaskSchedule::whereIn('task_id', $taskIds)->delete();

        // 插入新排程
        foreach ($newRows as $row) {
            TaskSchedule::create($this->engine->toDbFields($row));
        }

        // 更新任务状态：scheduled / plan 首尾 / 锁定
        foreach ($taskInfos as $taskId => $info) {
            /** @var ProdTask $task */
            $task = $info['task'];
            $ownRows = array_values(array_filter($newRows, fn ($r) => (int) $r['task_id'] === $taskId));

            $task->update([
                'route_id' => $task->route_id ?: ($task->product->default_route_id ?? null),
                'mold_id' => $info['mold_id'] ?: null, // 纯产线任务无模具（moldId=0）→ null
                'status' => ProdTask::STATUS_SCHEDULED,
                'plan_start' => $ownRows === [] ? null : min(array_map(fn ($r) => $r['plan_start'], $ownRows))->format('Y-m-d H:i:s'),
                'plan_end' => $ownRows === [] ? null : max(array_map(fn ($r) => $r['plan_end'], $ownRows))->format('Y-m-d H:i:s'),
                'is_locked' => 1, // 保存即锁定（B7）
            ]);

            // 排程冗余来源
            TaskSchedule::where('task_id', $taskId)->update(['source' => $source]);
        }

        // APS一期冻结区：未来 freeze_days 天内未完工排程标记 is_frozen=1。
        // 冻结区由解锁接口统一拦截，计划员不能直接移动；窗口外任务可先解锁再调整。
        $freezeUntil = Carbon::now()->addDays((int) config('aps.freeze_days', 3));
        // 先清空受影响任务的旧标记，再按新计划时间重算；否则任务从冻结窗口内
        // 手工移到窗口外后，is_frozen 会永久残留，甘特图和 forecast 防护都会误判。
        TaskSchedule::whereIn('task_id', $taskIds)
            ->update(['is_frozen' => 0]);
        TaskSchedule::whereIn('task_id', $taskIds)
            ->where('plan_start', '>=', Carbon::now())
            ->where('plan_start', '<=', $freezeUntil)
            ->where('status', '!=', TaskSchedule::STATUS_DONE)
            ->update(['is_frozen' => 1]);
    }

    /**
     * 行是否命中冲突清单
     */
    private function rowInConflicts(array $row, array $conflicts): bool
    {
        foreach ($conflicts as $conflict) {
            if ((int) $conflict['task_id_a'] === (int) $row['task_id'] || (int) $conflict['task_id_b'] === (int) $row['task_id']) {
                return true;
            }
        }

        return false;
    }

    /**
     * 受影响的订单状态聚合
     */
    private function syncOrders(array $orderIds): void
    {
        foreach ($orderIds as $orderId) {
            $order = Order::find($orderId);
            if ($order) {
                $this->orderService->syncStatus($order);
            }
        }
    }
}
