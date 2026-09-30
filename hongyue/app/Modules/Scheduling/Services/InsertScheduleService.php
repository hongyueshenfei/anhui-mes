<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Order;
use App\Models\ProdTask;
use App\Models\ScheduleVersion;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Order\Services\OrderService;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 插单服务（B4：紧急任务插入满负荷机台 → 后续自动顺延 + 延期清单 + 一键确认/回滚）
 *
 * 双策略（PRD T2/T9）：
 * - wait（等待）：插入任务顺延到机台当前占用结束之后，不打断任何任务
 * - interrupt（打断）：打断第一个占用任务（未开始=整行顺延；已开始=已完成部分留档 +
 *   剩余量重新排入插入任务之后），后续任务级联顺延；已锁定任务需 force_interrupt 显式确认
 *
 * preview() 纯计算不落库；confirm() 在同事务内应用并写版本快照（回滚支撑）。
 */
class InsertScheduleService
{
    /** @var list<array> 被打断任务状态（work 副本阶段） */
    private array $interruptedState = [];

    public function __construct(
        private readonly ScheduleEngineService $engine,
        private readonly VersionService $versionService,
        private readonly OrderService $orderService,
        private readonly SuggestionService $suggestionService,
    ) {
    }

    /**
     * 插单预览（纯计算，不落库）——GET /api/schedule/orders-affected 与前插单预览复用
     *
     * @return array{task: array, strategy: string, insert_chain: list<array>, interrupted: ?array,
     *               affected_orders: list<array>, changed_tasks: list<array>, before_rows: list<array>, after_rows: list<array>}
     *
     * @throws ScheduleException
     */
    public function preview(int $taskId, int $machineId, string $planStart, string $strategy = 'wait', ?int $moldId = null, bool $forceInterrupt = false): array
    {
        try {
            return $this->doPreview($taskId, $machineId, $planStart, $strategy, $moldId, $forceInterrupt);
        } catch (ScheduleException $e) {
            throw $e;
        }
    }

    /**
     * 插单预览（纯计算，不落库）——GET /api/schedule/orders-affected 与前插单预览复用
     *
     * @return array{task: array, strategy: string, insert_chain: list<array>, interrupted: ?array,
     *               affected_orders: list<array>, changed_tasks: list<array>, before_rows: list<array>, after_rows: list<array>}
     *
     * @throws ScheduleException
     */
    private function doPreview(int $taskId, int $machineId, string $planStart, string $strategy = 'wait', ?int $moldId = null, bool $forceInterrupt = false): array
    {
        $task = ProdTask::with('product', 'line:id,order_id')->findOrFail($taskId);

        $this->assertInsertable($task);
        $moldId = $this->engine->validateMatching($task, $machineId, $moldId);

        $beforeRows = $this->engine->loadWorkRows();
        $existingRows = $beforeRows; // 可变工作副本

        // 插入工序链（可能随 wait 策略后移）
        $chain = $this->engine->buildChain(
            $task,
            $machineId,
            $moldId,
            Carbon::parse($planStart),
            TaskSchedule::SOURCE_INSERT,
            $existingRows,
        );

        $this->interruptedState = [];
        $interrupted = null;
        $interruptedOnce = false;

        for ($round = 0; $round < 12; $round++) {
            $conflicts = $this->engine->findConflicts($chain, $existingRows);
            $machineConflict = $this->firstConflictOn($conflicts, 'machine:'.$machineId, $chain);
            if ($machineConflict === null) {
                break;
            }

            $occupant = $this->findRowForConflict(
                $existingRows,
                $machineConflict,
                (int) $task->id,
                'machine:'.$machineId,
            );

            if ($strategy === 'interrupt' && ! $interruptedOnce && $occupant !== null && $this->canInterrupt($occupant, $forceInterrupt)) {
                $interruptedOnce = true;
                $interrupted = $this->doInterrupt($occupant, $existingRows);
                $this->positionInterruptedAfterChain($chain);
                continue;
            }

            // wait / 打断后的剩余占用：顺延插入链
            if ($occupant === null) {
                break;
            }
            // 同机台切换必须保留 30 分钟安全间隔；只放在占用结束点会被 sweep 判定为冲突，
            // 进而反复尝试移动锚定的插单任务，最终把仍冲突的预览写入数据库。
            $occupantInterval = $this->engine->effectiveOccupancyInterval($occupant);
            if ($occupantInterval === null) {
                break;
            }
            $nextStart = $occupantInterval[1]->copy()->addMinutes(ScheduleEngineService::TASK_SWITCH_GAP_MIN);
            $chain = $this->engine->buildChain(
                $task,
                $machineId,
                $moldId,
                $nextStart,
                TaskSchedule::SOURCE_INSERT,
                $existingRows,
            );
            $this->positionInterruptedAfterChain($chain);
        }

        // 合并：插入链 + 被打断任务（留档/剩余/后续工序）加入工作副本
        $existingRows = array_merge($existingRows, $chain);
        $this->mergeInterrupted($existingRows);

        // 锚定任务：插入任务本身 + 被打断任务（紧急插单不被顺延，受影响者让位）
        $anchors = [(int) $task->id];
        if ($interrupted !== null) {
            $anchors[] = (int) $interrupted['task_id'];
        }

        // 级联顺延（同机台后续任务 + 同模具跨机台）
        $this->engine->sweep($existingRows, $anchors);

        $afterRows = $existingRows;

        // 受影响计算
        $changed = $this->computeChanged($beforeRows, $afterRows);

        return [
            'task' => $this->taskBrief($task),
            'strategy' => $strategy,
            'insert_chain' => $this->serializeRows($chain),
            'interrupted' => $interrupted,
            'changed_tasks' => $changed['tasks'],
            'affected_orders' => $changed['orders'],
            'before_rows' => $this->serializeRows($beforeRows),
            'after_rows' => $this->serializeRows($afterRows),
        ];
    }

    /**
     * 插单确认（事务内应用 + 版本快照 + 订单聚合）
     *
     * @return array{version: ScheduleVersion, task: array, affected_orders: list<array>, changed_tasks: list<array>}
     *
     * @throws ScheduleException
     */
    public function confirm(int $taskId, int $machineId, string $planStart, int $userId, string $strategy = 'wait', ?int $moldId = null, bool $forceInterrupt = false): array
    {
        // 预览必须在写入事务内基于同一份最新排程计算；如果先在事务外预览，
        // 另一请求在两次读取之间改了机台，确认就可能把过期快照覆盖回数据库。
        return DB::transaction(function () use ($taskId, $machineId, $planStart, $userId, $strategy, $moldId, $forceInterrupt) {
            $preview = $this->doPreview($taskId, $machineId, $planStart, $strategy, $moldId, $forceInterrupt);
            $task = ProdTask::with('product', 'line:id,order_id')->findOrFail($taskId);

            $beforeRows = $this->engine->loadWorkRows();

            // 只重写真正受影响的任务：预览快照是全量排程，不能把全系统任务
            // 都当作插单对象删除再重建，否则会破坏无关任务的锁定/报工关联。
            $taskIds = array_values(array_unique(array_filter(array_merge(
                [(int) $taskId],
                array_map('intval', array_column($preview['changed_tasks'], 'task_id')),
                $preview['interrupted'] !== null ? [(int) $preview['interrupted']['task_id']] : [],
            ))));
            $affectedAfterRows = array_values(array_filter(
                $preview['after_rows'],
                fn ($row) => in_array((int) ($row['task_id'] ?? 0), $taskIds, true),
            ));
            $oldScheduleIds = TaskSchedule::whereIn('task_id', $taskIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            // 1. 删除受影响任务旧排程
            TaskSchedule::whereIn('task_id', $taskIds)->delete();

            // 2. 写入终态排程（after_rows 中的行）
            $reportScheduleMap = [];
            foreach ($affectedAfterRows as $row) {
                $created = TaskSchedule::create($this->fieldsFromSerialized($row));
                $oldScheduleId = (int) ($row['id'] ?? 0);
                if ($oldScheduleId > 0
                    && (! isset($reportScheduleMap[$oldScheduleId]) || (int) ($row['is_interrupted'] ?? 0) === 1)) {
                    // 插单打断时原 schedule_id 会同时出现在“留档”和“剩余”两行，
                    // 历史报工必须归到留档行，不能落到新的待执行剩余行。
                    $reportScheduleMap[$oldScheduleId] = (int) $created->id;
                }
            }
            foreach ($reportScheduleMap as $oldScheduleId => $newScheduleId) {
                if (in_array((int) $oldScheduleId, $oldScheduleIds, true)) {
                    WorkReport::where('schedule_id', (int) $oldScheduleId)
                        ->update(['schedule_id' => $newScheduleId]);
                }
            }

            // 3. 任务状态：有行=scheduled+锁定；无行=回到 unscheduled（被整体移除者）
            foreach ($taskIds as $tid) {
                $own = array_values(array_filter($preview['after_rows'], fn ($r) => (int) $r['task_id'] === $tid));
                $taskModel = $tid === $task->id ? $task : ProdTask::find($tid);
                if (! $taskModel) {
                    continue;
                }
                if ($own === []) {
                    $taskModel->update([
                        'status' => ProdTask::STATUS_UNSCHEDULED,
                        'plan_start' => null,
                        'plan_end' => null,
                        'is_locked' => 0,
                    ]);
                } else {
                    $starts = array_map(fn ($r) => Carbon::parse($r['plan_start']), $own);
                    $ends = array_map(fn ($r) => Carbon::parse($r['plan_end']), $own);
                    $moldRow = collect($own)->first(fn ($r) => ! empty($r['mold_id']));
                    $taskModel->update([
                        'route_id' => $taskModel->route_id ?: ($taskModel->product->default_route_id ?? null),
                        // 插单任务可能只在产品上配置默认模具，确认时把工序链解析出的有效模具回写到任务。
                        'mold_id' => $moldRow ? (int) $moldRow['mold_id'] : $taskModel->mold_id,
                        'status' => ProdTask::STATUS_SCHEDULED,
                        'plan_start' => min($starts)->format('Y-m-d H:i:s'),
                        'plan_end' => max($ends)->format('Y-m-d H:i:s'),
                        'is_locked' => 1, // 保存即锁定
                    ]);
                }
            }

            // 4. 版本快照（全量 before/after）
            $afterFull = $this->engine->loadWorkRows();
            $version = $this->versionService->snapshot(
                $userId,
                ScheduleVersion::TYPE_INSERT,
                $beforeRows,
                $afterFull,
                $preview['affected_orders'],
                'insert:task:'.$taskId,
            );

            // 5. 订单状态聚合
            $orderIds = array_values(array_unique(array_filter(array_map(fn ($r) => (int) ($r['order_id'] ?? 0), $preview['after_rows']))));
            foreach ($orderIds as $orderId) {
                $order = Order::find($orderId);
                if ($order) {
                    $this->orderService->syncStatus($order);
                }
            }
            $this->suggestionService->refreshForTasks($taskIds);

            return [
                'version' => $version->load('creator:id,name,employee_no'),
                'task' => $this->taskBrief($task->fresh()->load('schedules')),
                'affected_orders' => $preview['affected_orders'],
                'changed_tasks' => $preview['changed_tasks'],
                'written_schedule_count' => count($preview['after_rows']),
            ];
        });
    }

    /**
     * 插单回滚 = 回滚对应版本（复用 VersionService::rollback）
     */
    public function rollback(int $versionId, int $userId): ScheduleVersion
    {
        return $this->versionService->rollback($versionId, $userId);
    }

    // ---------------- 内部 ----------------

    private function assertInsertable(ProdTask $task): void
    {
        if ($task->is_locked) {
            throw new ScheduleException("任务 {$task->task_no} 已锁定，请先解锁", [
                ['code' => 'task_locked', 'message' => '任务已锁定，请先解锁', 'task_id' => $task->id, 'task_no' => $task->task_no],
            ]);
        }
        if ($task->schedules()->exists()) {
            throw new ScheduleException("任务 {$task->task_no} 已有排程，不可重复插单", [
                ['code' => 'already_scheduled', 'message' => '任务已有排程，请先解锁回滚', 'task_id' => $task->id, 'task_no' => $task->task_no],
            ]);
        }
        // 任务主表的 in_progress 可能是历史残留；插单保护以实际排程/报工事实为准。
        // 已完成任务仍不可插单，避免覆盖已结算任务。
        if ($task->status === ProdTask::STATUS_DONE) {
            throw new ScheduleException("任务 {$task->task_no} 生产中/已完成，不可插单", [
                ['code' => 'task_not_editable', 'message' => '生产中/已完成任务不可插单', 'task_id' => $task->id, 'task_no' => $task->task_no],
            ]);
        }
    }

    private function canInterrupt(array $occupant, bool $forceInterrupt): bool
    {
        if (in_array($occupant['status'], [TaskSchedule::STATUS_RUNNING, TaskSchedule::STATUS_DONE], true)
            || ! empty($occupant['actual_start'])
            || ! empty($occupant['actual_end'])) {
            throw new ScheduleException("任务 {$occupant['task_no']} 进行中/已完成，不可打断", [
                ['code' => 'cannot_interrupt_running', 'message' => '进行中/已完成任务不可打断', 'task_id' => $occupant['task_id'], 'task_no' => $occupant['task_no']],
            ]);
        }
        if ((int) ($occupant['id'] ?? 0) > 0
            && WorkReport::where('schedule_id', (int) $occupant['id'])->exists()) {
            throw new ScheduleException("任务 {$occupant['task_no']} 已有实际报工，不可打断", [
                [
                    'code' => 'cannot_interrupt_reported',
                    'message' => '该工序已有实际报工流水，不能打断重建；请先完成/更正当前工序后再调整',
                    'task_id' => $occupant['task_id'],
                    'task_no' => $occupant['task_no'],
                    'schedule_id' => $occupant['id'],
                ],
            ]);
        }
        if ($occupant['task_locked'] && ! $forceInterrupt) {
            throw new ScheduleException("任务 {$occupant['task_no']} 已锁定，打断需显式确认（force_interrupt）", [
                ['code' => 'interrupt_locked', 'message' => '被插单任务已锁定，打断需显式确认', 'task_id' => $occupant['task_id'], 'task_no' => $occupant['task_no']],
            ]);
        }

        return true;
    }

    /**
     * 打断第一个占用任务：留档 + 剩余量 + 同任务其他工序行缓存
     *
     * @return array{task_id: int, task_no: string, done_qty: int, remaining_qty: int, archived: array, remaining: array}
     */
    private function doInterrupt(array $occupant, array &$existingRows): array
    {
        $taskRows = array_values(array_filter($existingRows, fn ($r) => (int) $r['task_id'] === (int) $occupant['task_id']));
        $existingRows = array_values(array_filter($existingRows, fn ($r) => (int) $r['task_id'] !== (int) $occupant['task_id']));

        // 插单拆分只能使用实际报工净数量，不能按“计划时间已经经过多久”推测完成量。
        // 班组长/统计员通常在每天收工时一次性报工，时间比例既不代表真实产量，
        // 也会把尚未报工的任务错误截断，进而污染后续排产和入库口径。
        $doneQty = $this->reportedDoneQty($occupant);

        if ($doneQty <= 0) {
            // 未开始：无留档，剩余 = 整行
            $remaining = $occupant;
            $remaining['is_interrupted'] = 0;
            $remaining['done_qty'] = 0;
            $remaining['source'] = TaskSchedule::SOURCE_INSERT;
            $remaining['note'] = '插单打断（未开始，整单顺延）';
            $remaining['actual_start'] = null;
            $remaining['actual_end'] = null;
            $remaining['forecast_start'] = $remaining['plan_start'];
            $remaining['forecast_end'] = $remaining['plan_end'];
            $archived = null;
        } else {
            // 已完成部分留档
            $archived = $occupant;
            $archived['is_interrupted'] = 1;
            $archived['done_qty'] = $doneQty;
            $archived['plan_end'] = Carbon::parse($archived['plan_end'])->gt(now()) ? now() : Carbon::parse($archived['plan_end']);
            $archived['note'] = '插单打断（已完成部分留档）';

            $remaining = $occupant;
            $remaining['qty'] = max(1, $occupant['qty'] - $doneQty);
            $remaining['duration'] = max(60, (int) round($occupant['duration'] * $remaining['qty'] / $occupant['qty']));
            $remaining['is_interrupted'] = 0;
            $remaining['done_qty'] = 0;
            $remaining['source'] = TaskSchedule::SOURCE_INSERT;
            $remaining['note'] = '插单打断（剩余量重排）';
            // 剩余量是新的待执行排程段，不能继承原段的实际/预测起止，
            // 否则会被占用引擎误判为已开工或继续占用旧时间窗。
            $remaining['actual_start'] = null;
            $remaining['actual_end'] = null;
            $remaining['forecast_start'] = $remaining['plan_start'];
            $remaining['forecast_end'] = $remaining['plan_end'];
        }

        // 同任务其他工序行（后续 ops），挂到剩余行之后（保持原相对间隔）
        $otherRows = array_values(array_filter($taskRows, fn ($r) => (int) $r['seq'] !== (int) $occupant['seq']));
        $otherRows = array_map(function ($r) use ($occupant, $remaining) {
            $r['source'] = TaskSchedule::SOURCE_INSERT;
            return $r;
        }, $otherRows);

        $this->interruptedState = [
            'archived' => $archived,
            'remaining' => $remaining,
            'other_rows' => $otherRows,
            'occupant_start' => Carbon::parse($occupant['plan_start']),
        ];

        return [
            'task_id' => (int) $occupant['task_id'],
            'task_no' => $occupant['task_no'],
            'done_qty' => $doneQty,
            'remaining_qty' => $remaining['qty'],
            'strategy' => 'interrupt',
        ];
    }

    /**
     * 将被打断任务（剩余行 + 后续工序行）定位到插入链结束之后
     */
    private function positionInterruptedAfterChain(array $chain): void
    {
        if ($this->interruptedState === []) {
            return;
        }

        $chainEnd = max(array_map(fn ($r) => Carbon::parse($r['plan_end'])->getTimestamp(), $chain));


        $remaining = $this->interruptedState['remaining'];
        $delta = $chainEnd - $this->interruptedState['occupant_start']->getTimestamp();

        $remaining['plan_start'] = $this->fromTs($chainEnd);
        $remaining['plan_end'] = $this->fromTs($chainEnd + $remaining['duration']);
        $remaining['forecast_start'] = $remaining['plan_start'];
        $remaining['forecast_end'] = $remaining['plan_end'];
        $this->interruptedState['remaining'] = $remaining;


        $otherRows = [];
        foreach ($this->interruptedState['other_rows'] as $row) {
            $row['plan_start'] = Carbon::parse($row['plan_start'])->addSeconds($delta);
            $row['plan_end'] = Carbon::parse($row['plan_end'])->addSeconds($delta);
            if (! empty($row['forecast_start'])) {
                $row['forecast_start'] = Carbon::parse($row['forecast_start'])->addSeconds($delta);
            }
            if (! empty($row['forecast_end'])) {
                $row['forecast_end'] = Carbon::parse($row['forecast_end'])->addSeconds($delta);
            }
            $otherRows[] = $row;
        }
        $this->interruptedState['other_rows'] = $otherRows;
    }

    /**
     * 合并打断状态回到工作副本（sweep 前调用）
     */
    private function mergeInterrupted(array &$existingRows): void
    {
        if ($this->interruptedState === []) {
            return;
        }
        if ($this->interruptedState['archived'] !== null) {
            $existingRows[] = $this->interruptedState['archived'];
        }
        $existingRows[] = $this->interruptedState['remaining'];
        foreach ($this->interruptedState['other_rows'] as $row) {
            $existingRows[] = $row;
        }
        $this->interruptedState = [];
    }

    /**
     * 读取被打断排程段的实际报工净数量。
     *
     * 存量数据可能只有 task_schedules.done_qty 没有报工流水，因此无流水时
     * 才回退到冗余字段；一旦存在流水，必须以流水 SUM(qty)（含冲减）为准。
     */
    private function reportedDoneQty(array $occupant): int
    {
        $scheduleId = (int) ($occupant['id'] ?? 0);
        $qty = max(0, (int) ($occupant['qty'] ?? 0));
        if ($scheduleId <= 0) {
            return min($qty, max(0, (int) ($occupant['done_qty'] ?? 0)));
        }

        $reports = WorkReport::where('schedule_id', $scheduleId);
        if ($reports->exists()) {
            return min($qty, max(0, (int) $reports->sum('qty')));
        }

        return min($qty, max(0, (int) ($occupant['done_qty'] ?? 0)));
    }

    /**
     * 第一个机台资源冲突（按开始时间最早的占用者）
     */
    private function firstConflictOn(array $conflicts, string $resourceKey, array $chain): ?array
    {
        $onMachine = array_values(array_filter($conflicts, fn ($c) => ($c['resource'] ?? '') === $resourceKey));
        if ($onMachine === []) {
            return null;
        }

        $chainStart = min(array_map(fn ($r) => Carbon::parse($r['plan_start'])->getTimestamp(), $chain));

        usort($onMachine, function ($a, $b) {
            return Carbon::parse($a['start_b'])->getTimestamp() <=> Carbon::parse($b['start_b'])->getTimestamp();
        });

        return $onMachine[0] ?? null;
    }

    private function findRowForConflict(array $rows, array $conflict, int $chainTaskId, string $resourceKey): ?array
    {
        $taskIdA = (int) ($conflict['task_id_a'] ?? 0);
        $taskIdB = (int) ($conflict['task_id_b'] ?? 0);
        $occupantTaskId = $taskIdA === $chainTaskId ? $taskIdB : $taskIdA;
        $occupantStart = $taskIdA === $chainTaskId
            ? ($conflict['start_b'] ?? null)
            : ($conflict['start_a'] ?? null);
        [$type, $id] = explode(':', $resourceKey, 2);

        foreach ($rows as $row) {
            if ((int) $row['task_id'] !== $occupantTaskId
                || ! $this->rowOnResource($row, $type, (int) $id)) {
                continue;
            }
            $interval = $this->engine->effectiveOccupancyInterval($row);
            if ($interval !== null && ($occupantStart === null || $interval[0]->format('Y-m-d H:i:s') === $occupantStart)) {
                return $row;
            }
        }

        // 兜底仅按任务和资源定位，兼容旧冲突载荷未携带有效占用起点的情况。
        foreach ($rows as $row) {
            if ((int) $row['task_id'] === $occupantTaskId
                && $this->rowOnResource($row, $type, (int) $id)) {
                return $row;
            }
        }

        return null;
    }

    private function rowOnResource(array $row, string $type, int $id): bool
    {
        return match ($type) {
            'machine' => (int) ($row['machine_id'] ?? 0) === $id,
            'mold' => (int) ($row['mold_id'] ?? 0) === $id,
            default => false,
        };
    }

    /**
     * 计算受影响任务与订单延期清单
     */
    private function computeChanged(array $beforeRows, array $afterRows): array
    {
        $beforeByTask = [];
        foreach ($beforeRows as $row) {
            if (! isset($beforeByTask[$row['task_id']])) {
                $beforeByTask[$row['task_id']] = ['ends' => [], 'task_no' => $row['task_no'], 'order_id' => $row['order_id'] ?? 0];
            }
            $beforeByTask[$row['task_id']]['ends'][] = Carbon::parse($row['plan_end'])->getTimestamp();
        }
        $afterByTask = [];
        foreach ($afterRows as $row) {
            if (! isset($afterByTask[$row['task_id']])) {
                $afterByTask[$row['task_id']] = ['ends' => [], 'task_no' => $row['task_no'], 'order_id' => $row['order_id'] ?? 0, 'start' => Carbon::parse($row['plan_start'])->getTimestamp()];
            }
            $afterByTask[$row['task_id']]['ends'][] = Carbon::parse($row['plan_end'])->getTimestamp();
        }

        $changedTasks = [];

        foreach ($beforeByTask as $taskId => $before) {
            if (! isset($afterByTask[$taskId])) {
                // 被整体移除
                $changedTasks[] = [
                    'task_id' => $taskId,
                    'task_no' => $before['task_no'],
                    'old_end' => $this->fromTs(max($before['ends']))->format('Y-m-d H:i:s'),
                    'new_end' => null,
                    'delay_hours' => 0,
                    'reason' => '排程被移除（插单替换）',
                ];
                continue;
            }
            $oldEnd = max($before['ends']);
            $newEnd = max($afterByTask[$taskId]['ends']);
            $delayHours = round(($newEnd - $oldEnd) / 3600, 1);
            if ($delayHours > 0.05) {
                $changedTasks[] = [
                    'task_id' => $taskId,
                    'task_no' => $afterByTask[$taskId]['task_no'],
                    'old_end' => $this->fromTs($oldEnd)->format('Y-m-d H:i:s'),
                    'new_end' => $this->fromTs($newEnd)->format('Y-m-d H:i:s'),
                    'delay_hours' => $delayHours,
                    'reason' => '插单自动顺延',
                ];
            }
        }

        // 按订单聚合
        $orders = [];
        foreach ($changedTasks as $task) {
            $orderId = (int) ($beforeByTask[$task['task_id']]['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            if (! isset($orders[$orderId])) {
                $orders[$orderId] = ['order_id' => $orderId, 'delay_hours' => 0, 'tasks' => []];
            }
            $orders[$orderId]['delay_hours'] = max($orders[$orderId]['delay_hours'], $task['delay_hours']);
            $orders[$orderId]['tasks'][] = $task;
        }

        // 订单头信息
        $orderModels = Order::whereIn('id', array_keys($orders))->get()->keyBy('id');
        $result = [];
        foreach ($orders as $orderId => $agg) {
            $model = $orderModels->get($orderId);
            $result[] = [
                'order_id' => $orderId,
                'order_no' => $model?->order_no ?? '',
                'customer_name' => $model?->customer_name ?? '',
                'delay_hours' => $agg['delay_hours'],
                'task_count' => count($agg['tasks']),
                'tasks' => $agg['tasks'],
            ];
        }

        usort($result, fn ($a, $b) => $b['delay_hours'] <=> $a['delay_hours']);

        return ['tasks' => $changedTasks, 'orders' => $result];
    }

    private function taskBrief(ProdTask $task): array
    {
        return [
            'id' => $task->id,
            'task_no' => $task->task_no,
            'product_id' => $task->product_id,
            'product_code' => $task->product->code ?? '',
            'product_name' => $task->product->name ?? '',
            'qty' => $task->qty,
            'priority' => $task->priority,
            'delivery_date' => $task->delivery_date?->format('Y-m-d'),
            'status' => $task->status,
            'is_locked' => (int) $task->is_locked,
        ];
    }

    /**
     * 时间戳 → 应用时区 Carbon（Carbon 3 createFromTimestamp 忽略默认时区，需显式设置）
     */
    private function fromTs(int $timestamp): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::createFromTimestampUTC($timestamp)->setTimezone(config('app.timezone'));
    }

    private function serializeRows(array $rows): array
    {
        return array_map(fn ($r) => [
            'key' => $r['key'],
            'id' => $r['id'],
            'task_id' => (int) $r['task_id'],
            'task_no' => $r['task_no'],
            'order_id' => (int) ($r['order_id'] ?? 0),
            'seq' => (int) $r['seq'],
            'route_op_id' => (int) $r['route_op_id'],
            'op_type' => $r['op_type'],
            'op_name' => $r['op_name'] ?? $r['op_type'],
            'machine_id' => $r['machine_id'],
            'mold_id' => $r['mold_id'],
            'plan_start' => Carbon::parse($r['plan_start'])->format('Y-m-d H:i:s'),
            'plan_end' => Carbon::parse($r['plan_end'])->format('Y-m-d H:i:s'),
            'qty' => (int) $r['qty'],
            'status' => $r['status'],
            'source' => $r['source'],
            'is_interrupted' => (int) ($r['is_interrupted'] ?? 0),
            'done_qty' => (int) ($r['done_qty'] ?? 0),
            'is_overlap' => (int) ($r['is_overlap'] ?? 0),
            'note' => $r['note'] ?? null,
            // 预览/确认必须携带 APS 与实际字段，否则插单重写会把既有报工状态抹掉。
            'forecast_start' => array_key_exists('forecast_start', $r)
                ? ($r['forecast_start'] ? Carbon::parse($r['forecast_start'])->format('Y-m-d H:i:s') : null)
                : Carbon::parse($r['plan_start'])->format('Y-m-d H:i:s'),
            'forecast_end' => array_key_exists('forecast_end', $r)
                ? ($r['forecast_end'] ? Carbon::parse($r['forecast_end'])->format('Y-m-d H:i:s') : null)
                : Carbon::parse($r['plan_end'])->format('Y-m-d H:i:s'),
            'actual_start' => array_key_exists('actual_start', $r) && $r['actual_start']
                ? Carbon::parse($r['actual_start'])->format('Y-m-d H:i:s') : null,
            'actual_end' => array_key_exists('actual_end', $r) && $r['actual_end']
                ? Carbon::parse($r['actual_end'])->format('Y-m-d H:i:s') : null,
            'is_frozen' => (int) ($r['is_frozen'] ?? 0),
            'changeover_minutes' => (int) ($r['changeover_minutes'] ?? $r['mold_change'] ?? 0),
        ], $rows);
    }

    private function fieldsFromSerialized(array $row): array
    {
        return [
            'task_id' => (int) $row['task_id'],
            'seq' => (int) $row['seq'],
            'route_op_id' => (int) $row['route_op_id'],
            'op_type' => $row['op_type'],
            'op_name' => $row['op_name'] ?? $row['op_type'],
            'machine_id' => $row['machine_id'] ?? null,
            'mold_id' => $row['mold_id'] ?? null,
            'plan_start' => $row['plan_start'],
            'plan_end' => $row['plan_end'],
            'qty' => (int) $row['qty'],
            'status' => $row['status'] ?? TaskSchedule::STATUS_PENDING,
            'source' => $row['source'] ?? TaskSchedule::SOURCE_INSERT,
            'is_interrupted' => (int) ($row['is_interrupted'] ?? 0),
            'done_qty' => (int) ($row['done_qty'] ?? 0),
            'is_overlap' => (int) ($row['is_overlap'] ?? 0),
            'note' => $row['note'] ?? null,
            'forecast_start' => array_key_exists('forecast_start', $row) ? $row['forecast_start'] : $row['plan_start'],
            'forecast_end' => array_key_exists('forecast_end', $row) ? $row['forecast_end'] : $row['plan_end'],
            'actual_start' => $row['actual_start'] ?? null,
            'actual_end' => $row['actual_end'] ?? null,
            'is_frozen' => (int) ($row['is_frozen'] ?? 0),
            'changeover_minutes' => (int) ($row['changeover_minutes'] ?? 0),
        ];
    }
}
