<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 为时间段字段上线前的历史正报工补齐可追溯的展示区间。
 *
 * 历史流水没有真实开始时间，不能声称恢复了现场事实。本次按：
 * 报工时间作为本段结束时间，数量 ÷ 资源产能估算本段时长，
 * 并把开始时间限制在工序计划起点与上一段结束时间之后。
 * 仅更新 start_time/end_time，不改数量、报工时间及任何状态字段。
 */
return new class extends Migration
{
    public function up(): void
    {
        $schedules = DB::table('task_schedules')
            ->get(['id', 'task_id', 'plan_start', 'plan_end', 'qty', 'machine_id'])
            ->keyBy('id');
        $capacities = DB::table('machines')->pluck('capacity_per_hour', 'id');

        // 只处理首轮兼容迁移生成的“同刻度时间点”，不覆盖用户已经填写的真实区间。
        $reports = DB::table('work_reports')
            ->where('qty', '>', 0)
            ->whereColumn('start_time', 'report_time')
            ->whereColumn('end_time', 'report_time')
            ->orderByRaw('COALESCE(schedule_id, 0)')
            ->orderBy('report_time')
            ->orderBy('id')
            ->get(['id', 'task_id', 'schedule_id', 'qty', 'report_time']);

        $previousEndByGroup = [];
        foreach ($reports as $report) {
            $schedule = $report->schedule_id ? $schedules->get($report->schedule_id) : null;
            $end = Carbon::parse($report->report_time);
            $groupKey = $report->schedule_id
                ? 'schedule:'.$report->schedule_id
                : 'task:'.$report->task_id;

            $planStart = $schedule?->plan_start
                ? Carbon::parse($schedule->plan_start)
                : $end->copy()->subHours(8);
            $availableStart = $previousEndByGroup[$groupKey] ?? $planStart;
            if ($availableStart->gt($end)) {
                $availableStart = $end->copy();
            }

            $capacity = $schedule?->machine_id
                ? (float) ($capacities->get($schedule->machine_id) ?? 0)
                : 0;
            $minutes = $capacity > 0
                ? (int) ceil(((int) $report->qty / $capacity) * 60)
                : $this->fallbackMinutes($schedule, (int) $report->qty);
            $minutes = max(1, $minutes);

            $start = $end->copy()->subMinutes($minutes);
            if ($start->lt($availableStart)) {
                $start = $availableStart->copy();
            }
            if ($start->gt($end)) {
                $start = $end->copy();
            }

            DB::table('work_reports')
                ->where('id', $report->id)
                ->update([
                    'start_time' => $start->format('Y-m-d H:i:s'),
                    'end_time' => $end->format('Y-m-d H:i:s'),
                ]);

            $previousEndByGroup[$groupKey] = $end;
        }
    }

    private function fallbackMinutes(?object $schedule, int $qty): int
    {
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
