<?php

namespace App\Modules\Scheduling\Services;

use App\Models\ProdTask;
use App\Models\ScheduleVersion;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Order\Services\OrderService;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use Illuminate\Support\Facades\DB;

/**
 * 排产版本服务（快照/回滚，D9）
 *
 * snapshot 结构：
 * {before: {schedules: TaskSchedule快照[], tasks: {task_id: 任务状态}}, after: {...}}
 * - 整版接受（ai）/局部采纳（ai partial）/插单确认（insert）/手工保存（manual）都会生成版本
 * - 回滚 = 恢复 before（重写受影响任务的全部排程与任务状态），并同步订单状态
 */
class VersionService
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {
    }
    /**
     * 创建版本快照（在调用方事务内执行）
     *
     * @param  list<array>  $beforeRows  操作前全量工作副本
     * @param  list<array>  $afterRows  操作后全量工作副本
     * @param  list<array>  $affectedOrders  受影响订单延期清单（插单）
     */
    public function snapshot(
        int $userId,
        string $type,
        array $beforeRows,
        array $afterRows,
        array $affectedOrders = [],
        ?string $bizKey = null,
    ): ScheduleVersion {
        $versionNo = (int) ScheduleVersion::max('version_no') + 1;

        return ScheduleVersion::create([
            'version_no' => $versionNo,
            'type' => $type,
            'status' => ScheduleVersion::STATUS_APPLIED,
            'snapshot' => [
                'before' => [
                    'schedules' => array_map([$this, 'rowToSnapshot'], $beforeRows),
                    'tasks' => $this->taskStates($beforeRows),
                ],
                'after' => [
                    'schedules' => array_map([$this, 'rowToSnapshot'], $afterRows),
                    'tasks' => $this->taskStates($afterRows),
                ],
            ],
            'affected_orders' => $affectedOrders ?: null,
            'biz_key' => $bizKey,
            'created_by' => $userId,
            'applied_at' => now(),
        ]);
    }

    /**
     * 回滚版本：恢复 before 全量状态
     *
     * @throws ScheduleException 版本不存在/已回滚/存在进行中排程
     */
    public function rollback(int $versionId, int $userId): ScheduleVersion
    {
        $version = ScheduleVersion::find($versionId);

        if (! $version) {
            throw new ScheduleException('版本不存在', [['code' => 'version_not_found', 'message' => '排产版本不存在']], 404);
        }
        if ($version->status !== ScheduleVersion::STATUS_APPLIED) {
            throw new ScheduleException('版本不可回滚（非生效状态）', [['code' => 'version_not_applied', 'message' => '版本当前状态不可回滚']]);
        }

        $snapshot = $version->snapshot;
        $before = $snapshot['before'] ?? [];
        $after = $snapshot['after'] ?? [];
        $beforeSchedules = $before['schedules'] ?? [];
        $beforeTasks = $before['tasks'] ?? [];
        $afterSchedules = $after['schedules'] ?? [];
        $afterTasks = $after['tasks'] ?? [];

        DB::transaction(function () use ($version, $beforeSchedules, $beforeTasks, $afterSchedules, $afterTasks, $userId) {
            // 快照保存的是全量工作副本，但回滚只应重写 before/after 真正发生变化的任务；
            // 否则无关任务的报工会把任意版本都判定为不可回滚。
            $taskIds = $this->changedTaskIds($beforeSchedules, $beforeTasks, $afterSchedules, $afterTasks);

            // 保护：进行中排程不可被回滚覆盖
            $running = TaskSchedule::whereIn('task_id', $taskIds)
                ->where(function ($query) {
                    $query->whereIn('status', [TaskSchedule::STATUS_RUNNING, TaskSchedule::STATUS_DONE])
                        ->orWhereNotNull('actual_start')
                        ->orWhereNotNull('actual_end');
                })
                ->exists();
            if ($running) {
                throw new ScheduleException('存在进行中/已完成排程，不可回滚（请先完结再操作）', [
                    ['code' => 'running_schedule', 'message' => '存在进行中/已完成排程，不可回滚'],
                ]);
            }

            // 报工台账只增不改，回滚会删除并重建排程；即使存量数据漏填
            // actual/status，也不能让 work_reports.schedule_id 指向已删除的行。
            if (WorkReport::whereIn('task_id', $taskIds)->exists()) {
                throw new ScheduleException('存在报工流水，不可回滚覆盖排程', [
                    ['code' => 'reported_task', 'message' => '涉及任务已有实际报工流水，不能通过版本回滚重建排程'],
                ]);
            }

            // 1. 删除涉及任务的全部当前排程
            TaskSchedule::whereIn('task_id', $taskIds)->delete();

            // 2. 恢复 before 排程
            $beforeByTask = [];
            foreach ($beforeSchedules as $row) {
                TaskSchedule::create($this->snapshotToFields($row));
                $beforeByTask[(int) $row['task_id']] = true;
            }

            // 3. 恢复任务状态：before 有记录 → 恢复；after 新增（before 无记录）→ 回退待排
            foreach ($taskIds as $taskId) {
                $taskId = (int) $taskId;
                if (isset($beforeTasks[$taskId])) {
                    ProdTask::whereKey($taskId)->update($beforeTasks[$taskId]);
                } else {
                    ProdTask::whereKey($taskId)->update([
                        'status' => ProdTask::STATUS_UNSCHEDULED,
                        'is_locked' => 0,
                        'plan_start' => null,
                        'plan_end' => null,
                    ]);
                }
            }

            // 4. 版本置为已回滚
            $version->update([
                'status' => ScheduleVersion::STATUS_ROLLED_BACK,
                'rolled_back_at' => now(),
            ]);

            // 5. 订单状态聚合（回滚后订单可能由已排产回到待排产）
            $lineIds = ProdTask::whereIn('id', $taskIds)->pluck('order_line_id')->unique();
            $orderIds = \App\Models\OrderLine::whereIn('id', $lineIds)->pluck('order_id')->unique();
            foreach ($orderIds as $orderId) {
                $order = \App\Models\Order::find($orderId);
                if ($order) {
                    $this->orderService->syncStatus($order);
                }
            }
        });

        return $version->fresh();
    }

    /**
     * 版本清单
     */
    public function index(int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return ScheduleVersion::with('creator:id,name,employee_no')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * 提取工作副本的任务状态字典
     */
    private function taskStates(array $rows): array
    {
        $taskIds = array_unique(array_column($rows, 'task_id'));
        $states = [];

        foreach ($taskIds as $taskId) {
            $task = ProdTask::find($taskId);
            if (! $task) {
                continue;
            }
            $ownRows = array_values(array_filter($rows, fn ($r) => (int) $r['task_id'] === (int) $taskId));
            $firstRow = $ownRows[0] ?? [];
            $starts = array_map(fn ($r) => $r['plan_start'], $ownRows);
            $ends = array_map(fn ($r) => $r['plan_end'], $ownRows);

            $states[$taskId] = [
                // 从工作副本读取任务元数据，不能在版本写入后重新查询当前任务，
                // 否则 before 快照会拿到 after 的状态/锁定/模具。
                'status' => $firstRow['task_status'] ?? ($ownRows === [] ? ProdTask::STATUS_UNSCHEDULED : ProdTask::STATUS_SCHEDULED),
                'is_locked' => $firstRow['task_is_locked'] ?? ($ownRows === [] ? 0 : (int) $task->is_locked),
                'plan_start' => $firstRow['task_plan_start'] ?? ($starts === [] ? null : min($starts)->format('Y-m-d H:i:s')),
                'plan_end' => $firstRow['task_plan_end'] ?? ($ends === [] ? null : max($ends)->format('Y-m-d H:i:s')),
                'mold_id' => array_key_exists('task_mold_id', $firstRow) ? $firstRow['task_mold_id'] : $task->mold_id,
                'route_id' => array_key_exists('task_route_id', $firstRow) ? $firstRow['task_route_id'] : $task->route_id,
            ];
        }

        return $states;
    }

    /**
     * 识别版本真正改动的任务（忽略排程自增 ID，避免“重写同一计划”被误判）。
     *
     * @return list<int>
     */
    private function changedTaskIds(array $beforeSchedules, array $beforeTasks, array $afterSchedules, array $afterTasks): array
    {
        $beforeByTask = $this->scheduleSignaturesByTask($beforeSchedules);
        $afterByTask = $this->scheduleSignaturesByTask($afterSchedules);
        $taskIds = array_values(array_unique(array_map('intval', array_merge(
            array_keys($beforeByTask),
            array_keys($afterByTask),
            array_keys($beforeTasks),
            array_keys($afterTasks),
        ))));
        $changed = [];

        foreach ($taskIds as $taskId) {
            $beforeState = $beforeTasks[$taskId] ?? $beforeTasks[(string) $taskId] ?? null;
            $afterState = $afterTasks[$taskId] ?? $afterTasks[(string) $taskId] ?? null;
            $beforeSignature = json_encode([
                'state' => $beforeState,
                'schedules' => $beforeByTask[$taskId] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $afterSignature = json_encode([
                'state' => $afterState,
                'schedules' => $afterByTask[$taskId] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($beforeSignature !== $afterSignature) {
                $changed[] = $taskId;
            }
        }

        return $changed;
    }

    /**
     * 按任务生成不含排程自增 ID 的稳定签名。
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function scheduleSignaturesByTask(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $taskId = (int) ($row['task_id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            $grouped[$taskId][] = [
                'seq' => (int) ($row['seq'] ?? 0),
                'route_op_id' => (int) ($row['route_op_id'] ?? 0),
                'op_type' => $row['op_type'] ?? null,
                'op_name' => $row['op_name'] ?? null,
                'machine_id' => $row['machine_id'] ?? null,
                'mold_id' => $row['mold_id'] ?? null,
                'plan_start' => $row['plan_start'] ?? null,
                'plan_end' => $row['plan_end'] ?? null,
                'qty' => (int) ($row['qty'] ?? 0),
                'status' => $row['status'] ?? null,
                'source' => $row['source'] ?? null,
                'is_interrupted' => (int) ($row['is_interrupted'] ?? 0),
                'done_qty' => (int) ($row['done_qty'] ?? 0),
                'is_overlap' => (int) ($row['is_overlap'] ?? 0),
                'note' => $row['note'] ?? null,
                'forecast_start' => $row['forecast_start'] ?? null,
                'forecast_end' => $row['forecast_end'] ?? null,
                'actual_start' => $row['actual_start'] ?? null,
                'actual_end' => $row['actual_end'] ?? null,
                'is_frozen' => (int) ($row['is_frozen'] ?? 0),
                'changeover_minutes' => (int) ($row['changeover_minutes'] ?? 0),
            ];
        }

        foreach ($grouped as &$taskRows) {
            usort($taskRows, fn ($a, $b) => strcmp(
                json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ));
        }
        unset($taskRows);

        return $grouped;
    }

    /**
     * 工作副本行 → 快照数组
     */
    public function rowToSnapshot(array $row): array
    {
        return [
            'id' => $row['id'],
            'task_id' => (int) $row['task_id'],
            'seq' => (int) $row['seq'],
            'route_op_id' => (int) $row['route_op_id'],
            'op_type' => $row['op_type'],
            'op_name' => $row['op_name'] ?? $row['op_type'],
            'machine_id' => $row['machine_id'],
            'mold_id' => $row['mold_id'],
            'plan_start' => $row['plan_start'] instanceof \DateTimeInterface ? $row['plan_start']->format('Y-m-d H:i:s') : $row['plan_start'],
            'plan_end' => $row['plan_end'] instanceof \DateTimeInterface ? $row['plan_end']->format('Y-m-d H:i:s') : $row['plan_end'],
            'qty' => (int) $row['qty'],
            'status' => $row['status'],
            'source' => $row['source'],
            'is_interrupted' => (int) ($row['is_interrupted'] ?? 0),
            'done_qty' => (int) ($row['done_qty'] ?? 0),
            'is_overlap' => (int) ($row['is_overlap'] ?? 0),
            'note' => $row['note'] ?? null,
            'forecast_start' => $this->snapshotDate($row['forecast_start'] ?? null),
            'forecast_end' => $this->snapshotDate($row['forecast_end'] ?? null),
            'actual_start' => $this->snapshotDate($row['actual_start'] ?? null),
            'actual_end' => $this->snapshotDate($row['actual_end'] ?? null),
            'is_frozen' => (int) ($row['is_frozen'] ?? 0),
            'changeover_minutes' => (int) ($row['changeover_minutes'] ?? $row['mold_change'] ?? 0),
        ];
    }

    /**
     * 快照数组 → 落库字段
     */
    private function snapshotToFields(array $row): array
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
            'source' => $row['source'] ?? TaskSchedule::SOURCE_MANUAL,
            'is_interrupted' => (int) ($row['is_interrupted'] ?? 0),
            'done_qty' => (int) ($row['done_qty'] ?? 0),
            'is_overlap' => (int) ($row['is_overlap'] ?? 0),
            'note' => $row['note'] ?? null,
            'forecast_start' => $row['forecast_start'] ?? null,
            'forecast_end' => $row['forecast_end'] ?? null,
            'actual_start' => $row['actual_start'] ?? null,
            'actual_end' => $row['actual_end'] ?? null,
            'is_frozen' => (int) ($row['is_frozen'] ?? 0),
            'changeover_minutes' => (int) ($row['changeover_minutes'] ?? 0),
        ];
    }

    private function snapshotDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d H:i:s')
            : (string) $value;
    }
}
