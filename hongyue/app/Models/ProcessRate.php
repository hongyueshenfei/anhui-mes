<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 工序费率（架构 §3.6 process_rates 表）—— 五期 E1
 *
 * rate_key 对应 route_ops.rate_key（如 injection_machine / paint_team）；
 * UNIQUE(rate_key, effective_date) 生效日期版本化；报价时取最近生效费率。
 */
class ProcessRate extends Model
{
    protected $fillable = [
        'rate_key',
        'op_type',
        'rate_type',
        'rate',
        'effective_date',
    ];

    protected $casts = [
        'rate' => 'float',
        'effective_date' => 'date',
    ];

    /**
     * 取某费率键在指定日期的生效费率（无则 null）
     */
    public static function rateAt(string $rateKey, ?string $date = null): ?self
    {
        $date = $date ?? now()->toDateString();

        return self::where('rate_key', $rateKey)
            ->whereDate('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();
    }
}
