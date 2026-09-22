<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 系统配置
 *
 * 对应架构 §3.1 system_configs 表：
 * config_key 如 shift.two_shift（班制时段）、shift.day、k3.poll_interval_min、redlight.deviation_pct
 * config_value 为 JSON 结构。
 */
class SystemConfig extends Model
{
    protected $fillable = [
        'config_key',
        'config_value',
        'remark',
    ];

    protected $casts = [
        'config_value' => 'array',
    ];

    /**
     * 取配置值（带默认值），未配置返回默认
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = self::where('config_key', $key)->first();

        return $row ? $row->config_value : $default;
    }

    /**
     * 设置配置值（存在则更新，不存在则创建）
     */
    public static function setValue(string $key, mixed $value, ?string $remark = null): self
    {
        return self::updateOrCreate(
            ['config_key' => $key],
            ['config_value' => $value, 'remark' => $remark]
        );
    }
}