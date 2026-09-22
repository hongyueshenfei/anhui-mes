<?php

namespace App\Modules\Plm\Services;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * 产能计算服务（排产与报价共用；一期提供生产时长预估）
 *
 * 口径：
 * - 成型工序按排产引擎解析出的实际换模分钟计入；生产时长合计再增加 5% 预留
 * - two_shift 两班制：全天 24 小时有效生产（白班 08:00-20:00 + 夜班 20:00-08:00）
 * - day 白班制：按配置时段（默认 08:00-17:00）
 */
class CapacityCalcService
{
    /** 无矩阵时的默认换模分钟；实际计算使用调用方传入的解析结果 */
    public const FIXED_MOLD_CHANGE_MIN = 30;
    public const RESERVE_RATE = 0.05;

    /**
     * 预估生产所需小时数（含换模）
     */
    public static function estimateHours(
        int $qty,
        float $capacityPerHour,
        int $moldChangeMin = 0,
    ): float {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('数量必须大于 0');
        }
        if ($capacityPerHour <= 0) {
            throw new \InvalidArgumentException('标准产能必须大于 0');
        }

        $changeoverHours = max(0, $moldChangeMin) / 60;
        return ($changeoverHours + $qty / $capacityPerHour) * (1 + self::RESERVE_RATE);
    }

    /**
     * 秒级精确预估（毫秒返回，供前端"输入 1000 件即时显示需 X 小时"）
     */
    public static function estimateSeconds(int $qty, float $capacityPerHour, int $moldChangeMin = 0): int
    {
        return (int) round(self::estimateHours($qty, $capacityPerHour, $moldChangeMin) * 3600);
    }

    /**
     * 预估结束时间：从开始时间起按班制累加有效生产时长
     *
     * @param  DateTimeInterface  $start  计划开始时间
     * @param  string  $shiftMode  two_shift / day
     * @param  array  $periods  班制时段（来自 ConfigService::shiftPeriods）
     */
    public static function estimateEndTime(
        DateTimeInterface $start,
        int $qty,
        float $capacityPerHour,
        int $moldChangeMin = 0,
        string $shiftMode = 'two_shift',
        array $periods = [],
    ): CarbonInterface {
        // 剩余需要生产的小时数（含换模）
        $remainingSeconds = self::estimateSeconds($qty, $capacityPerHour, $moldChangeMin);

        $cursor = Carbon::parse($start);

        if ($shiftMode === 'two_shift') {
            // 两班制：24 小时连续生产
            return $cursor->addSeconds($remainingSeconds);
        }

        // 白班制：只在有效时段内生产
        $period = $periods ?: ['start' => '08:00', 'end' => '17:00'];
        [$startHour, $startMin] = array_map('intval', explode(':', $period['start']));
        [$endHour, $endMin] = array_map('intval', explode(':', $period['end']));
        $workStart = $startHour * 3600 + $startMin * 60;
        $workEnd = $endHour * 3600 + $endMin * 60;
        $workSecondsPerDay = $workEnd - $workStart;
        if ($workSecondsPerDay <= 0) {
            throw new \InvalidArgumentException('班制时段配置不合法（end 必须晚于 start）');
        }

        while ($remainingSeconds > 0) {
            $dayStart = $cursor->copy()->startOfDay();
            // 用时间戳差值，避免 Carbon 3 diffInSeconds 符号歧义
            $secondsIntoDay = $cursor->getTimestamp() - $dayStart->getTimestamp();

            if ($secondsIntoDay < $workStart) {
                // 未到上班时间：跳到上班时间
                $cursor = $dayStart->copy()->addSeconds($workStart);
                $secondsIntoDay = $workStart;
            } elseif ($secondsIntoDay > $workEnd) {
                // 已过下班时间：顺延到次日
                $cursor = $dayStart->copy()->addDay()->addSeconds($workStart);
                continue;
            }

            $available = $workEnd - $secondsIntoDay;
            if ($remainingSeconds <= $available) {
                $cursor = $cursor->addSeconds($remainingSeconds);
                $remainingSeconds = 0;
            } else {
                $remainingSeconds -= $available;
                $cursor = $dayStart->copy()->addDay()->addSeconds($workStart);
            }
        }

        return $cursor;
    }

    /**
     * 预估开始时间（APS一期倒排）：从结束时间起按班制向前扣减有效生产时长
     *
     * 与 estimateEndTime 互为逆运算：
     * estimateEndTime(estimateStartTime(end, ...), ...) ≤ end（白班制下班窗口间的空隙保持为余量）
     *
     * @param  DateTimeInterface  $end  最晚结束时间（交期）
     * @param  string  $shiftMode  two_shift / day
     * @param  array  $periods  班制时段（来自 ConfigService::shiftPeriods）
     */
    public static function estimateStartTime(
        DateTimeInterface $end,
        int $qty,
        float $capacityPerHour,
        int $moldChangeMin = 0,
        string $shiftMode = 'two_shift',
        array $periods = [],
    ): CarbonInterface {
        // 需要向前扣减的生产秒数（含换模）
        $remainingSeconds = self::estimateSeconds($qty, $capacityPerHour, $moldChangeMin);

        $cursor = Carbon::parse($end);

        if ($shiftMode === 'two_shift') {
            // 两班制：24 小时连续生产，直接回退
            return $cursor->subSeconds($remainingSeconds);
        }

        // 白班制：只在有效时段内扣减
        $period = $periods ?: ['start' => '08:00', 'end' => '17:00'];
        [$startHour, $startMin] = array_map('intval', explode(':', $period['start']));
        [$endHour, $endMin] = array_map('intval', explode(':', $period['end']));
        $workStart = $startHour * 3600 + $startMin * 60;
        $workEnd = $endHour * 3600 + $endMin * 60;
        $workSecondsPerDay = $workEnd - $workStart;
        if ($workSecondsPerDay <= 0) {
            throw new \InvalidArgumentException('班制时段配置不合法（end 必须晚于 start）');
        }

        while ($remainingSeconds > 0) {
            $dayStart = $cursor->copy()->startOfDay();
            // 用时间戳差值，避免 Carbon 3 diffInSeconds 符号歧义
            $secondsIntoDay = $cursor->getTimestamp() - $dayStart->getTimestamp();

            if ($secondsIntoDay > $workEnd) {
                // 已过下班时间：回拉到当日下班时刻再扣减
                $cursor = $dayStart->copy()->addSeconds($workEnd);
                $secondsIntoDay = $workEnd;
            } elseif ($secondsIntoDay < $workStart) {
                // 未到上班时间（如夜间）：回退到前一日下班时刻
                $cursor = $dayStart->copy()->subDay()->addSeconds($workEnd);
                continue;
            }

            $available = $secondsIntoDay - $workStart;
            if ($remainingSeconds <= $available) {
                $cursor = $cursor->subSeconds($remainingSeconds);
                $remainingSeconds = 0;
            } else {
                $remainingSeconds -= $available;
                $cursor = $dayStart->copy()->subDay()->addSeconds($workEnd);
            }
        }

        return $cursor;
    }

    /**
     * 格式化人类可读时长："3.5 小时" / "2 天 3 小时"
     */
    public static function formatHours(float $hours): string
    {
        if ($hours < 24) {
            return number_format($hours, 1).' 小时';
        }

        $days = intdiv((int) round($hours), 24);
        $remain = round($hours - $days * 24, 1);

        return $remain > 0 ? "{$days} 天 {$remain} 小时" : "{$days} 天";
    }

    /**
     * 前端换算辅助：产能预览（数量 → 需要小时）
     */
    public static function preview(int $qty, ?float $capacityPerHour, ?int $moldChangeMin): array
    {
        if (! $capacityPerHour || $capacityPerHour <= 0) {
            return [
                'ok' => false,
                'message' => '未配置标准产能，无法估算（将使用机台/班组默认值）',
                'hours' => null,
            ];
        }

        $hours = self::estimateHours($qty, $capacityPerHour, $moldChangeMin ?? 0);

        return [
            'ok' => true,
            'message' => self::formatHours($hours),
            'hours' => round($hours, 2),
            'mold_change_min' => $moldChangeMin ?? 0,
            'pure_process_hours' => round($qty / $capacityPerHour, 2),
        ];
    }
}
