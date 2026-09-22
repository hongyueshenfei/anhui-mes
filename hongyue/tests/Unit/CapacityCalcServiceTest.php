<?php

namespace Tests\Unit;

use App\Modules\Plm\Services\CapacityCalcService;
use PHPUnit\Framework\TestCase;

/**
 * 产能计算单测（二期排产的产能换算基础）
 */
class CapacityCalcServiceTest extends TestCase
{
    public function test_estimate_hours_basic(): void
    {
        // （1000 件 ÷ 400 件/小时 + 30 分钟换模）× 1.05 = 3.15 小时
        $hours = CapacityCalcService::estimateHours(1000, 400, 30);

        $this->assertEqualsWithDelta(3.15, $hours, 0.001);
    }

    public function test_estimate_hours_without_mold_change(): void
    {
        $hours = CapacityCalcService::estimateHours(500, 250, 0);

        $this->assertEqualsWithDelta(2.1, $hours, 0.001);
    }

    public function test_estimate_hours_uses_actual_changeover_minutes(): void
    {
        // 矩阵解析出 90 分钟时，不能仍按默认 30 分钟计算。
        $hours = CapacityCalcService::estimateHours(400, 400, 90);

        $this->assertEqualsWithDelta(2.625, $hours, 0.001);
    }

    public function test_estimate_hours_invalid_qty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CapacityCalcService::estimateHours(0, 400);
    }

    public function test_estimate_hours_invalid_capacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CapacityCalcService::estimateHours(100, 0);
    }

    public function test_estimate_end_time_two_shift_is_continuous(): void
    {
        // 两班制：24 小时连续生产
        $start = new \DateTimeImmutable('2025-01-06 08:00:00');
        $end = CapacityCalcService::estimateEndTime($start, 4800, 400, 0, 'two_shift');

        // （4800 ÷ 400）× 1.05 = 12.6 小时
        $this->assertSame('2025-01-06 20:36:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_estimate_end_time_day_shift_crosses_night(): void
    {
        // 白班制（08:00-17:00）：从 16:00 开始 400 件（产能 400/h = 1 小时）
        // 16:00 + 1h 但 17:00 下班 → 剩余 0 小时顺延次日 08:00
        $start = new \DateTimeImmutable('2025-01-06 16:30:00');
        $end = CapacityCalcService::estimateEndTime($start, 200, 400, 0, 'day', [
            'start' => '08:00',
            'end' => '17:00',
        ]);

        // （0.5h × 1.05）= 31.5 分钟，白班内完成
        $this->assertSame('2025-01-07 08:01:30', $end->format('Y-m-d H:i:s'));
    }

    public function test_estimate_end_time_day_shift_rolls_to_next_day(): void
    {
        // 白班制：从 16:00 开始需要 2.1 小时 → 当天剩 1 小时，次日补 1.1 小时
        $start = new \DateTimeImmutable('2025-01-06 16:00:00');
        $end = CapacityCalcService::estimateEndTime($start, 800, 400, 0, 'day', [
            'start' => '08:00',
            'end' => '17:00',
        ]);

        $this->assertSame('2025-01-07 09:06:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_format_hours(): void
    {
        $this->assertSame('3.0 小时', CapacityCalcService::formatHours(3.0));
        $this->assertSame('2 天 3 小时', CapacityCalcService::formatHours(51.0));
    }
}
