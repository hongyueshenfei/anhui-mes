<?php

namespace App\Modules\Scheduling\Listeners;

use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Plm\Services\CapacityCalcService;
use App\Modules\Production\Events\ReportSaved;
use App\Modules\Scheduling\Services\ScheduleEngineService;
use App\Modules\Scheduling\Services\SuggestionService;
use App\Modules\System\Services\ConfigService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 报工 → APS forecast 滚动（APS一期第4步）
 *
 * 口径：
 * - actual 回填：首笔正报工 = actual_start；累计合格达工序量 = actual_end
 *   （与 OrderService::attachTaskExecutionMeta 口径一致）
 * - forecast 重算：当前工序按「剩余量 × 产能 × 班历」从 now 重算 forecast_end；
 *   plan_start/plan_end 永不被自动修改（计划基线不动，偏差体现在 forecast）
 * - 下游传递：同任务后续未完工工序按上游 forecast_end 链式重算 forecast（只写 forecast）
 * - 冻结区防护：报工直接触发的工序更新自身 forecast（执行实况）；
 *   下游遇 is_frozen=1 的行 → 跳过 + 告警并停止传递（冻结行不可自动移动）
 */
class UpdateForecast
{
    public function __construct(
        private readonly ScheduleEngineService $engine,
        private readonly SuggestionService $suggestionService,
    ) {
    }

    public function handle(ReportSaved $event): void
    {
        $scheduleId = $event->report->schedule_id;
        if ($scheduleId === null) {
            return; // 任务级报工（未挂工序排程）不参与 forecast 滚动
        }

        $schedule = TaskSchedule::find($scheduleId);
        if ($schedule === null) {
            return;
        }

        $machine = $schedule->machine;
        if (! $machine) {
            return;
        }

        // ---------- 1. actual 回填（首笔正报工 / 累计合格达工序量） ----------
        $firstReport = WorkReport::where('schedule_id', $schedule->id)
            ->where('sign', 1)
            ->orderByRaw('COALESCE(start_time, report_time)')
            ->first(['start_time', 'report_time']);
        $firstAt = $firstReport?->start_time ?? $firstReport?->report_time;
        $lastReport = WorkReport::where('schedule_id', $schedule->id)
            ->where('sign', 1)
            ->orderByRaw('COALESCE(end_time, report_time) DESC')
            ->first(['end_time', 'report_time']);
        $cumGood = (int) WorkReport::where('schedule_id', $schedule->id)->sum('qty');
        $netGood = max(0, $cumGood);

        $update = [];
        if ($firstAt !== null && $schedule->actual_start === null) {
            $update['actual_start'] = $firstAt;
        }
        if ($cumGood >= (int) $schedule->qty && (int) $schedule->qty > 0) {
            // 重新达到完工量时，若此前因冲减已清空 actual_end，则以本次报工重新记完工时间。
            $update['actual_end'] = $schedule->actual_end
                ?? ($lastReport?->end_time ?? $lastReport?->report_time ?? $event->report->end_time ?? $event->report->report_time);
        } else {
            // 冲减使净完成量低于计划量时，已完工标记必须回退；actual_start 保留为实际开工留痕。
            $update['actual_end'] = null;
        }
        // 排程行状态/已报数量与流水保持一致，避免任务级已完成而工序明细仍显示待执行。
        $update['done_qty'] = $netGood;
        if ($netGood >= (int) $schedule->qty && (int) $schedule->qty > 0) {
            $update['status'] = TaskSchedule::STATUS_DONE;
        } elseif ($netGood > 0) {
            $update['status'] = $schedule->status === TaskSchedule::STATUS_PAUSED
                ? TaskSchedule::STATUS_PAUSED
                : TaskSchedule::STATUS_RUNNING;
        } else {
            $update['status'] = TaskSchedule::STATUS_PENDING;
        }

        // ---------- 2. 当前工序 forecast_end 重算（plan 不动） ----------
        $remaining = max(0, (int) $schedule->qty - $netGood);
        $capacity = (float) ($machine->capacity_per_hour ?: 0);

        if ($remaining === 0) {
            $update['forecast_end'] = $update['actual_end'] ?? $schedule->actual_end;
        } elseif ($capacity > 0) {
            $shiftMode = $machine->shift_mode ?: 'two_shift';
            $base = Carbon::now();
            $forecastStart = $schedule->forecast_start ?? $schedule->plan_start;
            // 超前报工（早于预计开始）保守以原预计起点为基准，不提前 forecast
            if ($base->lt(Carbon::parse($forecastStart))) {
                $base = Carbon::parse($forecastStart);
            }
            $update['forecast_end'] = CapacityCalcService::estimateEndTime(
                $base,
                $remaining,
                $capacity,
                0, // 换模在开工时已发生，滚动只算剩余生产
                $shiftMode,
                ConfigService::shiftPeriods($shiftMode),
            )->format('Y-m-d H:i:s');
        }

        if ($update !== []) {
            $schedule->update($update);
        }

        // ---------- 3. 下游传递（只写 forecast，永不改 plan） ----------
        $schedule->refresh();
        $cursorEnd = Carbon::parse($schedule->forecast_end ?? $schedule->plan_end);

        $downstream = TaskSchedule::where('task_id', $schedule->task_id)
            ->where('seq', '>', $schedule->seq)
            ->where('is_interrupted', 0)
            ->where('status', '!=', TaskSchedule::STATUS_DONE)
            ->orderBy('seq')
            ->get();

        foreach ($downstream as $row) {
            if ((int) $row->is_frozen === 1) {
                Log::warning('APS forecast 传递遇冻结工序，跳过并停止传递', [
                    'schedule_id' => $row->id,
                    'task_id' => $row->task_id,
                    'seq' => $row->seq,
                ]);
                break;
            }

            $rowMachine = $row->machine;
            $rowCapacity = (float) ($rowMachine?->capacity_per_hour ?: 0);
            if (! $rowMachine || $rowCapacity <= 0) {
                break; // 数据缺失无法重算，停止传递
            }

            $shiftMode = $rowMachine->shift_mode ?: 'two_shift';
            $periods = ConfigService::shiftPeriods($shiftMode);
            $fStart = $this->engine->effectiveStart($cursorEnd, $shiftMode, $periods);
            $fEnd = CapacityCalcService::estimateEndTime(
                $fStart,
                (int) $row->qty,
                $rowCapacity,
                (int) $row->changeover_minutes,
                $shiftMode,
                $periods,
            );

            $row->update([
                'forecast_start' => $fStart->format('Y-m-d H:i:s'),
                'forecast_end' => $fEnd->format('Y-m-d H:i:s'),
            ]);

            $cursorEnd = $fEnd;
        }

        // ---------- 4. 顺延建议评估（建议层：只生成建议，绝不自动改排程） ----------
        $this->suggestionService->evaluateAfterReport($event->task, (int) $event->report->id);
    }
}
