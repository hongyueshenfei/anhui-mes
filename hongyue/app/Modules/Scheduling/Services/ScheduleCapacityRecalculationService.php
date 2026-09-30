<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Machine;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 产能维护后的存量排程联动。
 *
 * 产能是机台/产线主数据，task_schedules 保存的是当时的计划快照；
 * 产能变化后，只对修改当天及之后的未开工计划按当前产能重算；当天以前的计划、实际时间和报工事实保持不变。
 */
class ScheduleCapacityRecalculationService
{
    public function __construct(
        private readonly ScheduleEngineService $engine,
        private readonly SuggestionService $suggestionService,
    )
    {
    }

    /**
     * 重算引用指定机台/产线的未完工工单，并重新执行资源避让。
     *
     * @return array{task_count:int, changed_count:int}
     */
    public function recalculateForMachine(Machine $machine): array
    {
        return DB::transaction(function () use ($machine): array {
            $taskIds = TaskSchedule::query()
                ->where('machine_id', $machine->id)
                ->where('is_interrupted', 0)
                ->whereHas('task', fn ($query) => $query->where('status', '!=', ProdTask::STATUS_DONE))
                ->pluck('task_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            return $this->recalculateTaskIds($taskIds);
        });
    }

    /**
     * 按当前所有机台/产线的最新产能重算全部未完工排程。
     *
     * 这是甘特图“重算排程”按钮使用的入口，与“自动排”完全分开：
     * 不创建算法建议，只重算已经存在且尚未开工的工序计划。
     *
     * @return array{task_count:int, changed_count:int}
     */
    public function recalculateAll(): array
    {
        return DB::transaction(function (): array {
            $taskIds = TaskSchedule::query()
                ->where('is_interrupted', 0)
                ->whereHas('task', fn ($query) => $query->where('status', '!=', ProdTask::STATUS_DONE))
                ->pluck('task_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            return $this->recalculateTaskIds($taskIds);
        });
    }

    /**
     * @param  list<int>  $taskIds
     * @return array{task_count:int, changed_count:int}
     */
    private function recalculateTaskIds(array $taskIds): array
    {
        if ($taskIds === []) {
            return ['task_count' => 0, 'changed_count' => 0];
        }

        // 产能主数据从“修改当天”起生效，历史排程不能因为显式重算被回算。
        $effectiveFrom = Carbon::now()->startOfDay();
        $before = $this->engine->loadWorkRows();
        $rows = $before;
        foreach ($taskIds as $taskId) {
            $this->engine->recomputeTaskChain($rows, $taskId, $effectiveFrom);
        }

        // 产能变大/变小都可能造成后续任务相交；资源避让也不能移动当天以前的历史行。
        // 重算时已开工任务只作为时间锚点，不应因未开工任务避让失败而整体报错。
        $this->engine->sweep($rows, [], $effectiveFrom, true);

        $beforeById = [];
        foreach ($before as $row) {
            if (! empty($row['id'])) {
                $beforeById[(int) $row['id']] = $row;
            }
        }
        $changedTaskIds = [];
        $changedCount = 0;
        foreach ($rows as $row) {
            $scheduleId = (int) ($row['id'] ?? 0);
            if ($scheduleId <= 0 || empty($beforeById[$scheduleId])) {
                continue;
            }
            $old = $beforeById[$scheduleId];
            if ($old['plan_start']->eq($row['plan_start']) && $old['plan_end']->eq($row['plan_end'])) {
                continue;
            }

            $updates = [
                'plan_start' => Carbon::parse($row['plan_start'])->format('Y-m-d H:i:s'),
                'plan_end' => Carbon::parse($row['plan_end'])->format('Y-m-d H:i:s'),
            ];
            // 未发生报工滚动时，预计时间跟随新计划；已经滚动的预计时间保留，
            // 避免一次产能维护覆盖现场进度形成的偏差。
            if (($old['forecast_start'] ?? null)?->eq($old['plan_start'])) {
                $updates['forecast_start'] = $updates['plan_start'];
            }
            if (($old['forecast_end'] ?? null)?->eq($old['plan_end'])) {
                $updates['forecast_end'] = $updates['plan_end'];
            }
            TaskSchedule::whereKey($scheduleId)->update($updates);
            $changedTaskIds[(int) $row['task_id']] = true;
            $changedCount++;
        }

        foreach (array_keys($changedTaskIds) as $taskId) {
            $task = ProdTask::find((int) $taskId);
            if (! $task || $task->status === ProdTask::STATUS_DONE) {
                continue;
            }
            $scheduleRows = $task->schedules()->where('is_interrupted', 0)->get();
            if ($scheduleRows->isEmpty()) {
                continue;
            }
            $task->update([
                'plan_start' => $scheduleRows->min('plan_start'),
                'plan_end' => $scheduleRows->max('plan_end'),
            ]);
        }

        // 产能重算只改正式计划，不自动采纳建议；但建议层必须立即按新 forecast 刷新。
        $this->suggestionService->refreshForTasks($taskIds);

        return ['task_count' => count($changedTaskIds), 'changed_count' => $changedCount];
    }
}
