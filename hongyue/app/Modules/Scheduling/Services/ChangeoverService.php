<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Machine;
use App\Models\MoldChangeMatrix;
use App\Models\ProdTask;
use App\Models\RouteOp;
use App\Models\TaskSchedule;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * 换模时间解析服务（APS一期）
 *
 * 解析优先级：mold_change_matrices（前序→当前 模具对） > route_ops.mold_change_min > machines.mold_change_min > 0
 * - 命中 is_forbidden=1（如黑料→白料需清机）→ 抛 ScheduleException（changeover_forbidden）
 * - 冷启动（机台前无排程）或无矩阵记录 → 走工序/机台默认值（与既有行为一致，回归无损）
 */
class ChangeoverService
{
    /**
     * 解析本工序采用的换模分钟数
     *
     * @param  int|null  $fromMoldId  机台上紧邻前序排程的模具（null=冷启动/非成型，走兜底）
     * @param  int|null  $toMoldId    本工序模具（成型工序必有）
     * @param  RouteOp|null  $op      工艺工序（取工序侧默认换模）
     * @return int 采用的换模分钟数
     *
     * @throws ScheduleException 命中禁止切换（changeover_forbidden）
     */
    public function resolve(?int $fromMoldId, ?int $toMoldId, ?RouteOp $op, Machine $machine): int
    {
        if ($fromMoldId !== null && $toMoldId !== null) {
            $pair = MoldChangeMatrix::where('from_mold_id', $fromMoldId)
                ->where('to_mold_id', $toMoldId)
                ->first();

            if ($pair !== null) {
                if ((int) $pair->is_forbidden === 1) {
                    $fromCode = $pair->fromMold?->code ?? $fromMoldId;
                    $toCode = $pair->toMold?->code ?? $toMoldId;
                    throw new ScheduleException("模具 {$fromCode} → {$toCode} 禁止切换（".$pair->remark.'）', [
                        [
                            'code' => 'changeover_forbidden',
                            'message' => "模具 {$fromCode} → {$toCode} 禁止切换，请调整排程顺序或更换模具",
                            'from_mold_id' => (int) $fromMoldId,
                            'to_mold_id' => (int) $toMoldId,
                            'remark' => $pair->remark,
                        ],
                    ]);
                }

                return (int) $pair->changeover_minutes;
            }
        }

        return $this->fallback($op, $machine);
    }

    /**
     * 兜底换模时间：工序默认 > 机台默认 > 0（与既有 buildChain 口径一致）
     */
    public function fallback(?RouteOp $op, Machine $machine): int
    {
        return (int) ($op?->mold_change_min ?? $machine->mold_change_min ?? 0);
    }

    /**
     * 机台上紧邻指定时刻之前的最后一副模具（换模矩阵的 from 端）。
     *
     * 说明：打断留档行（is_interrupted=1）同样物理占用过机台，一并参与；
     * 与待排时段重叠的排程不参与（重叠本身会被冲突检测拦截）。
     *
     * @param  list<array>  $plannedRows 本次请求中已构建但尚未落库的排程行
     * @param  int|null  $excludeTaskId 重排当前任务时排除其旧排程，避免把自身旧模具当成前序
     */
    public function previousMoldOnMachine(
        int $machineId,
        CarbonInterface $start,
        array $plannedRows = [],
        ?int $excludeTaskId = null,
    ): ?int {
        $candidates = [];

        // 不能只按 plan_end 查询：实际报工提前完成、或预测时间晚于计划时，
        // 资源释放边界可能与计划结束不同。先放宽候选，再用同一有效结束口径过滤。
        $storedRows = TaskSchedule::query()
            ->where('machine_id', $machineId)
            ->whereNotNull('mold_id')
            ->where(function ($query) use ($start) {
                $query->where('plan_end', '<=', $start)
                    ->orWhere('actual_end', '<=', $start)
                    ->orWhere('forecast_end', '<=', $start);
            })
            ->get(['task_id', 'mold_id', 'plan_start', 'plan_end', 'forecast_end', 'actual_end', 'status']);

        foreach ($storedRows as $row) {
            if ($excludeTaskId !== null && (int) $row->task_id === $excludeTaskId) {
                continue;
            }
            $end = $this->effectiveEnd($row);
            if ($end !== null && $end->lte($start)) {
                $candidates[] = ['end' => $end, 'mold_id' => (int) $row->mold_id];
            }
        }

        foreach ($plannedRows as $row) {
            if ((int) ($row['machine_id'] ?? 0) !== $machineId
                || empty($row['mold_id'])
                || ($excludeTaskId !== null && (int) ($row['task_id'] ?? 0) === $excludeTaskId)
                || (int) ($row['is_interrupted'] ?? 0) === 1) {
                continue;
            }
            $end = $this->effectiveEnd($row);
            if ($end !== null && $end->lte($start)) {
                $candidates[] = ['end' => $end, 'mold_id' => (int) $row['mold_id']];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b) => $b['end']->getTimestamp() <=> $a['end']->getTimestamp());

        return $candidates[0]['mold_id'];
    }

    private function effectiveEnd(TaskSchedule|array $row): ?CarbonInterface
    {
        $planEnd = $row instanceof TaskSchedule ? $row->plan_end : ($row['plan_end'] ?? null);
        if (! $planEnd) {
            return null;
        }

        $actualEnd = $row instanceof TaskSchedule ? $row->actual_end : ($row['actual_end'] ?? null);
        if ($actualEnd) {
            return Carbon::parse($actualEnd);
        }

        $end = Carbon::parse($planEnd);
        $status = $row instanceof TaskSchedule ? $row->status : ($row['status'] ?? null);
        $taskStatus = is_array($row) ? ($row['task_status'] ?? null) : null;
        // 已完工行应与 ScheduleEngineService::effectiveOccupancyInterval 保持一致：
        // 预测字段是未完工滚动用的，不能让历史完工任务继续占用机台。
        if ($status === TaskSchedule::STATUS_DONE || $taskStatus === ProdTask::STATUS_DONE) {
            return $end;
        }

        $forecastEnd = $row instanceof TaskSchedule ? $row->forecast_end : ($row['forecast_end'] ?? null);
        if ($forecastEnd && Carbon::parse($forecastEnd)->gt($end)) {
            $end = Carbon::parse($forecastEnd);
        }

        return $end;
    }
}
