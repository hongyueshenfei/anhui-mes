<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 调整首轮兼容迁移生成的历史报工区间。
 *
 * 首轮迁移把 report_time 当成了每段结束时间，导致首笔报工经常只显示几十分钟。
 * 本次仅识别首轮迁移生成的区间，按数量 ÷ 资源产能重新估算每段时长：
 * 首段从工序计划起点开始，后续段按报工时间锚定并避开上一段，保留真实的报工间隔。
 * 数量、报工时间、状态及用户已填写的非兼容区间均不修改。
 */
return new class extends Migration
{
    public function up(): void
    {
        $schedules = DB::table('task_schedules')
            ->get(['id', 'task_id', 'plan_start', 'plan_end', 'qty', 'machine_id'])
            ->keyBy('id');
        $capacities = DB::table('machines')->pluck('capacity_per_hour', 'id');

        $reports = DB::table('work_reports')
            ->where('qty', '>', 0)
            ->whereColumn('end_time', 'report_time')
            ->orderByRaw('COALESCE(schedule_id, 0)')
            ->orderBy('report_time')
            ->orderBy('id')
            ->get(['id', 'task_id', 'schedule_id', 'qty', 'report_time', 'start_time', 'end_time']);

        $previousOldEndByGroup = [];
        $previousNewEndByGroup = [];

        foreach ($reports as $report) {
            $schedule = $report->schedule_id ? $schedules->get($report->schedule_id) : null;
            $reportTime = Carbon::parse($report->report_time);
            $groupKey = $report->schedule_id
                ? 'schedule:'.$report->schedule_id
                : 'task:'.$report->task_id;

            $minutes = max(1, $this->estimateMinutes($schedule, $capacities, (int) $report->qty));
            $planStart = $schedule?->plan_start
                ? Carbon::parse($schedule->plan_start)
                : $reportTime->copy()->subMinutes($minutes);

            // 复算首轮迁移的旧起点，用于保护用户已经填写过的真实区间。
            $oldAvailableStart = $previousOldEndByGroup[$groupKey] ?? $planStart->copy();
            if ($oldAvailableStart->gt($reportTime)) {
                $oldAvailableStart = $reportTime->copy();
            }
            $oldStart = $reportTime->copy()->subMinutes($minutes);
            if ($oldStart->lt($oldAvailableStart)) {
                $oldStart = $oldAvailableStart->copy();
            }
            if ($oldStart->gt($reportTime)) {
                $oldStart = $reportTime->copy();
            }

            $isFirstMigrationRange = $report->start_time
                && Carbon::parse($report->start_time)->format('Y-m-d H:i:s') === $oldStart->format('Y-m-d H:i:s')
                && $report->end_time
                && Carbon::parse($report->end_time)->format('Y-m-d H:i:s') === $reportTime->format('Y-m-d H:i:s');

            $newStart = null;
            $newEnd = null;
            if ($isFirstMigrationRange) {
                $previousNewEnd = $previousNewEndByGroup[$groupKey] ?? null;
                if ($previousNewEnd === null) {
                    $newStart = $planStart->copy();
                    // 计划起点晚于报工时间时，退回到报工时间前的估算区间，避免出现反向时间。
                    if ($newStart->gt($reportTime)) {
                        $newStart = $reportTime->copy()->subMinutes($minutes);
                    }
                } else {
                    $candidateStart = $reportTime->copy()->subMinutes($minutes);
                    $newStart = $previousNewEnd->gt($candidateStart)
                        ? $previousNewEnd->copy()
                        : $candidateStart;
                }
                $newEnd = $newStart->copy()->addMinutes($minutes);

                DB::table('work_reports')
                    ->where('id', $report->id)
                    ->update([
                        'start_time' => $newStart->format('Y-m-d H:i:s'),
                        'end_time' => $newEnd->format('Y-m-d H:i:s'),
                    ]);
            }

            // 旧区间用于判断后续记录是否仍是首轮迁移生成的；新区间用于避免实际段重叠。
            $previousOldEndByGroup[$groupKey] = $reportTime->copy();
            $previousNewEndByGroup[$groupKey] = $newEnd
                ?? ($report->end_time ? Carbon::parse($report->end_time) : $reportTime->copy());
        }
    }

    private function estimateMinutes(?object $schedule, $capacities, int $qty): int
    {
        $capacity = $schedule?->machine_id
            ? (float) ($capacities->get($schedule->machine_id) ?? 0)
            : 0;

        if ($capacity > 0) {
            return (int) ceil(($qty / $capacity) * 60);
        }

        if ($schedule === null || (int) $schedule->qty <= 0 || empty($schedule->plan_start) || empty($schedule->plan_end)) {
            return 8 * 60;
        }

        $planMinutes = Carbon::parse($schedule->plan_start)->diffInMinutes(Carbon::parse($schedule->plan_end));

        return (int) ceil($planMinutes * $qty / (int) $schedule->qty);
    }

    public function down(): void
    {
        // 历史真实时间和本次估算时间无法区分，回滚不能安全覆盖已填写的时间段，因此不回写。
    }
};
