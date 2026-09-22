<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 让历史报工的展示时长与工序计划时长保持同一口径。
 *
 * 首轮估算按数量 ÷ 资源小时产能计算，对人工产线会忽略班次和工作日，
 * 造成实际段明显短于计划段。本次仅识别上一轮估算生成的区间，
 * 改按「工序计划时长 × 本段报工数量 ÷ 工序计划数量」重算。
 */
return new class extends Migration
{
    public function up(): void
    {
        $schedules = DB::table('task_schedules')
            ->get(['id', 'plan_start', 'plan_end', 'qty'])
            ->keyBy('id');

        $capacities = DB::table('machines')->pluck('capacity_per_hour', 'id');
        $machineSchedules = DB::table('task_schedules')
            ->whereNotNull('machine_id')
            ->get(['id', 'machine_id'])
            ->keyBy('id');

        $reports = DB::table('work_reports')
            ->where('qty', '>', 0)
            ->whereNotNull('schedule_id')
            ->orderBy('schedule_id')
            ->orderBy('report_time')
            ->orderBy('id')
            ->get(['id', 'schedule_id', 'qty', 'report_time', 'start_time', 'end_time']);

        $previousGeneratedEnd = [];
        $previousPlanEnd = [];

        foreach ($reports as $report) {
            $schedule = $schedules->get($report->schedule_id);
            if ($schedule === null) {
                continue;
            }

            $reportTime = Carbon::parse($report->report_time);
            $capacityMinutes = max(1, $this->capacityMinutes(
                $schedule,
                $machineSchedules->get($report->schedule_id),
                $capacities,
                (int) $report->qty,
            ));
            $planStart = !empty($schedule->plan_start)
                ? Carbon::parse($schedule->plan_start)
                : $reportTime->copy()->subMinutes($capacityMinutes);

            // 还原 000003 生成的区间，用于避免覆盖用户已经填写的真实时间。
            $oldStart = $previousGeneratedEnd[$report->schedule_id] ?? null;
            if ($oldStart === null) {
                $oldStart = $planStart->copy();
                if ($oldStart->gt($reportTime)) {
                    $oldStart = $reportTime->copy()->subMinutes($capacityMinutes);
                }
            } else {
                $candidateStart = $reportTime->copy()->subMinutes($capacityMinutes);
                if ($candidateStart->gt($oldStart)) {
                    $oldStart = $candidateStart;
                }
            }
            $oldEnd = $oldStart->copy()->addMinutes($capacityMinutes);

            $isPreviousEstimate = $report->start_time
                && Carbon::parse($report->start_time)->format('Y-m-d H:i:s') === $oldStart->format('Y-m-d H:i:s')
                && $report->end_time
                && Carbon::parse($report->end_time)->format('Y-m-d H:i:s') === $oldEnd->format('Y-m-d H:i:s');

            $newEnd = null;
            if ($isPreviousEstimate) {
                $planMinutes = max(1, $this->planMinutes($schedule, (int) $report->qty));
                $previousEnd = $previousPlanEnd[$report->schedule_id] ?? null;

                if ($previousEnd === null) {
                    $newStart = $planStart->copy();
                    if ($newStart->gt($reportTime)) {
                        $newStart = $reportTime->copy()->subMinutes($planMinutes);
                    }
                } else {
                    $candidateStart = $reportTime->copy()->subMinutes($planMinutes);
                    $newStart = $previousEnd->gt($candidateStart)
                        ? $previousEnd->copy()
                        : $candidateStart;
                }
                $newEnd = $newStart->copy()->addMinutes($planMinutes);

                DB::table('work_reports')
                    ->where('id', $report->id)
                    ->update([
                        'start_time' => $newStart->format('Y-m-d H:i:s'),
                        'end_time' => $newEnd->format('Y-m-d H:i:s'),
                    ]);
            }

            $previousGeneratedEnd[$report->schedule_id] = $oldEnd;
            $previousPlanEnd[$report->schedule_id] = $newEnd
                ?? ($report->end_time ? Carbon::parse($report->end_time) : $oldEnd);
        }
    }

    private function capacityMinutes(?object $schedule, ?object $machine, $capacities, int $qty): int
    {
        $capacity = $machine?->machine_id
            ? (float) ($capacities->get($machine->machine_id) ?? 0)
            : 0;

        if ($capacity > 0) {
            return (int) ceil(($qty / $capacity) * 60);
        }

        return $this->planMinutes($schedule, $qty);
    }

    private function planMinutes(object $schedule, int $qty): int
    {
        if ((int) $schedule->qty <= 0 || empty($schedule->plan_start) || empty($schedule->plan_end)) {
            return 8 * 60;
        }

        $planMinutes = Carbon::parse($schedule->plan_start)->diffInMinutes(Carbon::parse($schedule->plan_end));

        return max(1, (int) ceil($planMinutes * $qty / (int) $schedule->qty));
    }

    public function down(): void
    {
        // 历史真实时间和估算时间无法安全区分，因此不回写。
    }
};
