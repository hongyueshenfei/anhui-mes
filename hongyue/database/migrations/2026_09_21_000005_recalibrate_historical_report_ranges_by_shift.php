<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 按资源班制校准历史报工展示区间。
 *
 * 前序兼容迁移只能根据数量推算连续自然时间，人工产线因此把白班报工
 * 铺进了夜间。本次仅识别 000004 生成的区间，按计划窗口内的有效工时
 * 分配各段数量；用户已经填写的真实起止时间不覆盖。
 */
return new class extends Migration
{
    public function up(): void
    {
        $schedules = DB::table('task_schedules')
            ->get(['id', 'plan_start', 'plan_end', 'qty', 'machine_id'])
            ->keyBy('id');
        $shiftModes = DB::table('machines')->pluck('shift_mode', 'id');
        $dayPeriod = $this->dayPeriod();

        $reports = DB::table('work_reports')
            ->where('qty', '>', 0)
            ->whereNotNull('schedule_id')
            ->orderBy('schedule_id')
            ->orderBy('report_time')
            ->orderBy('id')
            ->get(['id', 'schedule_id', 'qty', 'report_time', 'start_time', 'end_time']);

        $previousContinuousEnd = [];
        $previousShiftEnd = [];

        foreach ($reports as $report) {
            $schedule = $schedules->get($report->schedule_id);
            if ($schedule === null) {
                continue;
            }

            $reportTime = Carbon::parse($report->report_time);
            $planMinutes = ! empty($schedule->plan_start) && ! empty($schedule->plan_end) && (int) $schedule->qty > 0
                ? (int) ceil(
                    Carbon::parse($schedule->plan_start)->diffInMinutes(Carbon::parse($schedule->plan_end))
                    * (int) $report->qty / (int) $schedule->qty,
                )
                : 8 * 60;
            $planMinutes = max(1, $planMinutes);
            $planStart = ! empty($schedule->plan_start)
                ? Carbon::parse($schedule->plan_start)
                : $reportTime->copy()->subMinutes($planMinutes);

            // 还原 000004 生成的连续自然时间区间，保护用户已录入的真实区间。
            $oldStart = $previousContinuousEnd[$report->schedule_id] ?? $planStart->copy();
            if (! isset($previousContinuousEnd[$report->schedule_id])) {
                if ($oldStart->gt($reportTime)) {
                    $oldStart = $reportTime->copy()->subMinutes($planMinutes);
                }
            } else {
                $candidateStart = $reportTime->copy()->subMinutes($planMinutes);
                if ($candidateStart->gt($oldStart)) {
                    $oldStart = $candidateStart;
                }
            }
            $oldEnd = $oldStart->copy()->addMinutes($planMinutes);

            $isPreviousEstimate = $report->start_time
                && Carbon::parse($report->start_time)->format('Y-m-d H:i:s') === $oldStart->format('Y-m-d H:i:s')
                && $report->end_time
                && Carbon::parse($report->end_time)->format('Y-m-d H:i:s') === $oldEnd->format('Y-m-d H:i:s');

            $newEnd = null;
            if ($isPreviousEstimate) {
                $shiftMode = ($shiftModes->get($schedule->machine_id) ?: 'day') === 'two_shift'
                    ? 'two_shift'
                    : 'day';
                $durationMinutes = max(1, $this->plannedWorkingMinutes($schedule, $shiftMode, $dayPeriod));
                $durationMinutes = max(1, (int) ceil($durationMinutes * (int) $report->qty / max(1, (int) $schedule->qty)));
                $previousEnd = $previousShiftEnd[$report->schedule_id] ?? null;

                if ($previousEnd === null) {
                    $newStart = $planStart->copy();
                    if ($newStart->gt($reportTime)) {
                        $newStart = $this->subtractWorkingMinutes($reportTime, $durationMinutes, $shiftMode, $dayPeriod);
                    }
                } else {
                    $candidateStart = $this->subtractWorkingMinutes($reportTime, $durationMinutes, $shiftMode, $dayPeriod);
                    $newStart = $previousEnd->gt($candidateStart)
                        ? $previousEnd->copy()
                        : $candidateStart;
                }

                $newEnd = $this->addWorkingMinutes($newStart, $durationMinutes, $shiftMode, $dayPeriod);

                DB::table('work_reports')
                    ->where('id', $report->id)
                    ->update([
                        'start_time' => $newStart->format('Y-m-d H:i:s'),
                        'end_time' => $newEnd->format('Y-m-d H:i:s'),
                    ]);
            }

            $previousContinuousEnd[$report->schedule_id] = $oldEnd;
            $previousShiftEnd[$report->schedule_id] = $newEnd
                ?? ($report->end_time ? Carbon::parse($report->end_time) : $oldEnd);
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

    private function capacityMinutes(object $schedule, $capacities, int $qty): int
    {
        $capacity = $schedule->machine_id
            ? (float) ($capacities->get($schedule->machine_id) ?? 0)
            : 0;

        if ($capacity > 0) {
            return (int) ceil($qty / $capacity * 60);
        }

        if ((int) $schedule->qty <= 0 || empty($schedule->plan_start) || empty($schedule->plan_end)) {
            return 8 * 60;
        }

        return max(1, (int) ceil(
            Carbon::parse($schedule->plan_start)->diffInMinutes(Carbon::parse($schedule->plan_end))
            * $qty / (int) $schedule->qty,
        ));
    }

    private function plannedWorkingMinutes(object $schedule, string $shiftMode, array $dayPeriod): int
    {
        if (empty($schedule->plan_start) || empty($schedule->plan_end)) {
            return 8 * 60;
        }

        $start = Carbon::parse($schedule->plan_start);
        $end = Carbon::parse($schedule->plan_end);

        return $shiftMode === 'two_shift'
            ? max(1, $start->diffInMinutes($end))
            : max(1, $this->workingMinutesBetween($start, $end, $dayPeriod));
    }

    private function workingMinutesBetween(Carbon $start, Carbon $end, array $period): int
    {
        if ($end->lte($start)) {
            return 0;
        }

        $cursor = $start->copy();
        $minutes = 0;
        for ($guard = 0; $guard < 10000 && $cursor->lt($end); $guard++) {
            [$windowStart, $windowEnd] = $this->windowFor($cursor, $period);
            if ($cursor->lt($windowStart)) {
                $cursor = $windowStart;
                continue;
            }
            if ($cursor->gte($windowEnd)) {
                $cursor = $windowStart->copy()->addDay();
                [$nextStart] = $this->windowFor($cursor, $period);
                $cursor = $nextStart;
                continue;
            }

            $segmentEnd = $end->lt($windowEnd) ? $end->copy() : $windowEnd;
            $minutes += (int) floor(($segmentEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            $cursor = $segmentEnd;
        }

        return $minutes;
    }

    private function addWorkingMinutes(Carbon $start, int $minutes, string $shiftMode, array $period): Carbon
    {
        if ($shiftMode === 'two_shift') {
            return $start->copy()->addMinutes($minutes);
        }

        $cursor = $start->copy();
        $remaining = $minutes;
        for ($guard = 0; $guard < 10000 && $remaining > 0; $guard++) {
            [$windowStart, $windowEnd] = $this->windowFor($cursor, $period);
            if ($cursor->lt($windowStart)) {
                $cursor = $windowStart;
            } elseif ($cursor->gte($windowEnd)) {
                $cursor = $windowStart->copy()->addDay();
                [$windowStart] = $this->windowFor($cursor, $period);
                $cursor = $windowStart;
                continue;
            }

            $available = (int) floor(($windowEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
            if ($remaining <= $available) {
                return $cursor->addMinutes($remaining);
            }

            $remaining -= max(0, $available);
            $cursor = $windowStart->copy()->addDay();
            [$nextStart] = $this->windowFor($cursor, $period);
            $cursor = $nextStart;
        }

        return $cursor;
    }

    private function subtractWorkingMinutes(Carbon $end, int $minutes, string $shiftMode, array $period): Carbon
    {
        if ($shiftMode === 'two_shift') {
            return $end->copy()->subMinutes($minutes);
        }

        $cursor = $end->copy();
        $remaining = $minutes;
        for ($guard = 0; $guard < 10000 && $remaining > 0; $guard++) {
            [$windowStart, $windowEnd] = $this->windowFor($cursor, $period);
            if ($cursor->gt($windowEnd)) {
                $cursor = $windowEnd;
            } elseif ($cursor->lte($windowStart)) {
                $cursor = $windowStart->copy()->subDay();
                [, $previousEnd] = $this->windowFor($cursor, $period);
                $cursor = $previousEnd;
                continue;
            }

            $available = (int) floor(($cursor->getTimestamp() - $windowStart->getTimestamp()) / 60);
            if ($remaining <= $available) {
                return $cursor->subMinutes($remaining);
            }

            $remaining -= max(0, $available);
            $cursor = $windowStart->copy()->subDay();
            [, $previousEnd] = $this->windowFor($cursor, $period);
            $cursor = $previousEnd;
        }

        return $cursor;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function windowFor(Carbon $cursor, array $period): array
    {
        [$startHour, $startMinute] = array_map('intval', explode(':', (string) $period['start']));
        [$endHour, $endMinute] = array_map('intval', explode(':', (string) $period['end']));
        $dayStart = $cursor->copy()->startOfDay();

        return [
            $dayStart->copy()->setTime($startHour, $startMinute),
            $dayStart->copy()->setTime($endHour, $endMinute),
        ];
    }

    public function down(): void
    {
        // 历史真实时间和估算时间无法安全区分，回滚不覆盖已写入的时间段。
    }
};
