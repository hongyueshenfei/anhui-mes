<?php

namespace App\Modules\System\Services;

use App\Models\SystemConfig;

/**
 * 系统配置服务（读配置统一入口，供各业务模块使用）
 */
class ConfigService
{
    /**
     * 取配置值（带默认值）
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return SystemConfig::getValue($key, $default);
    }

    /**
     * 设置配置值
     */
    public static function set(string $key, mixed $value, ?string $remark = null): SystemConfig
    {
        return SystemConfig::setValue($key, $value, $remark);
    }

    /**
     * 班制时段配置（排产/产能计算用）
     * 返回结构：{shift1: {start, end}, shift2: {start, end} | null}
     */
    public static function shiftPeriods(string $shiftMode = 'two_shift'): array
    {
        $key = 'shift.'.($shiftMode === 'day' ? 'day' : 'two_shift');

        $value = self::get($key);

        if (is_array($value)) {
            return $value;
        }

        // 默认值兜底（与 ShiftConfigSeeder 一致）
        return $shiftMode === 'day'
            ? ['start' => '08:00', 'end' => '17:00']
            : [
                'shift1' => ['name' => '白班', 'start' => '08:00', 'end' => '20:00'],
                'shift2' => ['name' => '夜班', 'start' => '20:00', 'end' => '08:00'],
            ];
    }
}