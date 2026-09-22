<?php

namespace App\Modules\Production\Services;

use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Modules\System\Services\ConfigService;

/**
 * 任务状态服务（报工/入库联动）
 *
 * - done_qty = 成品完成量 = 最后一道工序（或任务级）报工合格数（中间工序完成不入成品口径）
 * - 状态流转：当前工序部分完成/任务级报工 → in_progress；完成 ≥ qty → done
 * - deviation_pct：实际进度 − 时间进度（落后 >10% 标红，四期红灯）
 */
class TaskStatusService
{
    /**
     * 同步任务进度/状态（报工后事务内调用）
     *
     * @return array{done_qty: int, defect_qty: int, status: string, deviation_pct: float}
     */
    public function sync(ProdTask $task): array
    {
        $state = $this->calculate($task);

        $task->update([
            'done_qty' => $state['done_qty'],
            'status' => $state['status'],
            'deviation_pct' => $state['deviation_pct'],
        ]);

        return $state;
    }

    /**
     * 计算任务派生进度，不写入数据库。
     *
     * 成品完成量只认末工序/任务级报工；任务状态则认整个任务是否已经开始，
     * 这样成型或喷漆先报工时，任务会进入「生产中」，不会被错误地留在「已排产」。
     *
     * @return array{done_qty: int, defect_qty: int, status: string, deviation_pct: float}
     */
    public function calculate(ProdTask $task): array
    {
        // 成品口径（2026-08-31 业务确认）：最后一道工序（max seq）报工 + 任务级报工；
        // 中间工序（成型/喷漆等）完成量单独留痕，不累加进任务成品完成量
        $lastScheduleIds = $this->lastProcessScheduleIds($task);
        $reports = $task->reports()->where(function ($query) use ($lastScheduleIds) {
            $query->whereNull('schedule_id');
            if ($lastScheduleIds->isNotEmpty()) {
                $query->orWhereIn('schedule_id', $lastScheduleIds);
            }
        });

        // qty 冲减时为负（符号已编码在数量内），累加直接用 SUM(qty)
        $qtySum = (int) $reports->sum(\Illuminate\Support\Facades\DB::raw('qty'));
        $defectSum = (int) $task->reports()->sum(\Illuminate\Support\Facades\DB::raw('defect_qty'));

        $doneQty = max(0, $qtySum);

        // 状态口径与成品完成量分开：当前工序存在部分完成量，或任务级已有报工，
        // 才代表任务正在生产。已经完成的上道工序、尚未开始的下道工序，仍属于「已排产」，
        // 这样末工序冲减后不会因为历史工序报工而错误保持「生产中」。
        $allReports = $task->reports()->get(['schedule_id', 'qty']);
        $taskLevelQty = (int) $allReports->whereNull('schedule_id')->sum('qty');
        $scheduleQty = $allReports->whereNotNull('schedule_id')->groupBy('schedule_id')->map(fn ($rows) => (int) $rows->sum('qty'));
        $hasActiveProcess = $task->schedules()
            ->get()
            ->groupBy('seq')
            ->contains(function ($processSchedules) use ($scheduleQty): bool {
                $planned = (int) $processSchedules->sum('qty');
                $reported = (int) $processSchedules->sum(fn ($schedule) => $scheduleQty->get($schedule->id, 0));

                return $reported > 0 && $reported < $planned;
            });

        $status = $task->status;
        if ($doneQty >= (int) $task->qty) {
            $status = ProdTask::STATUS_DONE;
        } elseif ($taskLevelQty > 0 || $hasActiveProcess) {
            // 当前工序仍有进行中的净报工：任务处于生产中；人工暂停状态继续保留。
            $status = $task->status === ProdTask::STATUS_PAUSED
                ? ProdTask::STATUS_PAUSED
                : ProdTask::STATUS_IN_PROGRESS;
        } else {
            // 净完成量归零时不能继续保留 done，否则订单会被错误聚合为已完成。
            // 有排程的任务回到已排产，无排程的任务回到待排产。
            $status = $task->schedules()->exists()
                ? ProdTask::STATUS_SCHEDULED
                : ProdTask::STATUS_UNSCHEDULED;
        }

        $deviation = $this->deviationPct($task, $doneQty);

        return [
            'done_qty' => $doneQty,
            'defect_qty' => $defectSum,
            'status' => $status,
            'deviation_pct' => $deviation,
        ];
    }

    /**
     * 进度偏差% = 实际完成进度 − 时间进度（负=落后）
     */
    public function deviationPct(ProdTask $task, int $doneQty): float
    {
        $actualPct = $task->qty > 0 ? $doneQty / $task->qty * 100 : 0.0;

        if ($task->plan_start === null || $task->plan_end === null || $task->plan_end->lte($task->plan_start)) {
            return round($actualPct, 2);
        }

        $now = now();
        if ($now->lte($task->plan_start)) {
            $timePct = 0.0;
        } elseif ($now->gte($task->plan_end)) {
            $timePct = 100.0;
        } else {
            $total = $task->plan_end->getTimestamp() - $task->plan_start->getTimestamp();
            $elapsed = $now->getTimestamp() - $task->plan_start->getTimestamp();
            $timePct = $total > 0 ? $elapsed / $total * 100 : 0.0;
        }

        return round($actualPct - $timePct, 2);
    }

    /**
     * 允许超报比例上限%（默认 100 = 允许超报 100%，即最多 2 倍任务量）
     */
    public function overReportPct(): int
    {
        $value = ConfigService::get('schedule.over_report_pct');

        return is_numeric($value) ? (int) $value : 100;
    }

    /**
     * 超量校验：累计净合格 + 本次净报 ≤ 任务量 × (1 + 超报%/100)
     */
    public function assertWithinAllowance(ProdTask $task, int $netAdd): void
    {
        if ($netAdd <= 0) {
            return; // 冲减不设上限
        }

        $pct = $this->overReportPct();
        $allowed = (int) $task->qty * (100 + $pct) / 100;
        // 成品口径：仅最后工序（或任务级）报工累计参与超报校验，中间工序不入成品累计
        $lastScheduleIds = $this->lastProcessScheduleIds($task);
        $cumulativeQuery = $task->reports()->where(function ($query) use ($lastScheduleIds) {
            $query->whereNull('schedule_id');
            if ($lastScheduleIds->isNotEmpty()) {
                $query->orWhereIn('schedule_id', $lastScheduleIds);
            }
        });
        $cumulative = (int) $cumulativeQuery
            ->sum(\Illuminate\Support\Facades\DB::raw('qty'));

        if ($cumulative + $netAdd > $allowed) {
            throw new \App\Modules\Production\Exceptions\ReportException(
                "累计报工将超过允许上限（任务量 {$task->qty} + 允许超报 {$pct}% = {$allowed}）",
                'over_report_limit',
                ['task_id' => $task->id, 'task_no' => $task->task_no, 'cumulative' => $cumulative, 'allowed' => (int) $allowed],
            );
        }
    }

    /**
     * 最后一道工序的全部排程段（含插单打断留档段）。
     * 同一工序可能被拆成历史段+当前段，任务成品进度必须汇总整道工序。
     */
    private function lastProcessScheduleIds(ProdTask $task): \Illuminate\Support\Collection
    {
        $lastSeq = TaskSchedule::where('task_id', $task->id)->max('seq');
        if ($lastSeq === null) {
            return collect();
        }

        return TaskSchedule::where('task_id', $task->id)
            ->where('seq', $lastSeq)
            ->pluck('id');
    }
}
