<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 白班历史报工一条记录只保留一个连续作业段。
 *
 * 000005 为了表达跨天有效工时增加了 work_segments 展示，但客户口径确认：
 * 一条报工记录不能包含多个时间段。因此对已有白班跨天区间取首个有效白班段，
 * 不改数量、报工时间或报工流水条数。
 */
return new class extends Migration
{
    public function up(): void
    {
        $period = $this->dayPeriod();
        $reports = DB::table('work_reports')
            ->join('task_schedules', 'task_schedules.id', '=', 'work_reports.schedule_id')
            ->join('machines', 'machines.id', '=', 'task_schedules.machine_id')
            ->where('work_reports.qty', '>', 0)
            ->where('machines.shift_mode', 'day')
            ->get([
                'work_reports.id',
                'work_reports.start_time',
                'work_reports.end_time',
                'work_reports.report_time',
            ]);

        foreach ($reports as $report) {
            $start = Carbon::parse($report->start_time ?: $report->report_time);
            $end = Carbon::parse($report->end_time ?: $report->report_time);
            $segment = $this->firstWorkingSegment($start, $end, $period);
            if ($segment === null) {
                continue;
            }

            if ($start->format('Y-m-d H:i:s') === $segment[0]->format('Y-m-d H:i:s')
                && $end->format('Y-m-d H:i:s') === $segment[1]->format('Y-m-d H:i:s')) {
                continue;
            }

            DB::table('work_reports')
                ->where('id', $report->id)
                ->update([
                    'start_time' => $segment[0]->format('Y-m-d H:i:s'),
                    'end_time' => $segment[1]->format('Y-m-d H:i:s'),
                ]);
        }
    }

    private function dayPeriod(): array
    {
        $raw = DB::table('system_configs')->where('config_key', 'shift.day')->value('config_value');
        $period = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($period) && isset($period['start'], $period['end'])
            ? $period
            : ['start' => '08:00', 'end' => '17:00'];
    }

    /** @return array{0: Carbon, 1: Carbon}|null */
    private function firstWorkingSegment(Carbon $start, Carbon $end, array $period): ?array
    {
        if ($end->lt($start)) {
            return null;
        }

        [$startHour, $startMinute] = array_map('intval', explode(':', (string) $period['start']));
        [$endHour, $endMinute] = array_map('intval', explode(':', (string) $period['end']));
        $cursor = $start->copy();

        for ($guard = 0; $guard < 10000; $guard++) {
            $dayStart = $cursor->copy()->startOfDay();
            $windowStart = $dayStart->copy()->setTime($startHour, $startMinute);
            $windowEnd = $dayStart->copy()->setTime($endHour, $endMinute);

            if ($cursor->lt($windowStart)) {
                $cursor = $windowStart;
            } elseif ($cursor->gte($windowEnd)) {
                $cursor = $dayStart->copy()->addDay()->setTime($startHour, $startMinute);
                continue;
            }

            $segmentEnd = $end->lt($windowEnd) ? $end->copy() : $windowEnd;

            return [$cursor, $segmentEnd];
        }

        return null;
    }

    public function down(): void
    {
        // 历史实际时间无法安全恢复为跨天区间，回滚不回写。
    }
};
