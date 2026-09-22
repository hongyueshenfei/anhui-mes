<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 报价规则（架构 §3.6 quote_rules 表）—— 五期 E1
 *
 * rule_key：loss_rate 行业损耗率 % / mold_amort_rule 摊销规则（flat=全额摊销，按数量摊薄）/
 * min_margin 毛利下限 % / anomaly_deviation 异常价偏差 %（默认 30）。
 */
class QuoteRule extends Model
{
    /** 规则键 */
    public const RULE_LOSS_RATE = 'loss_rate';
    public const RULE_MOLD_AMORT = 'mold_amort_rule';
    public const RULE_MIN_MARGIN = 'min_margin';
    public const RULE_ANOMALY_DEVIATION = 'anomaly_deviation';

    protected $fillable = [
        'rule_key',
        'rule_value',
        'remark',
    ];

    protected $casts = [
        'rule_value' => 'float',
    ];

    /**
     * 取规则值（带默认）
     */
    public static function valueOf(string $key, float $default = 0): float
    {
        $row = self::where('rule_key', $key)->first();

        return $row ? (float) $row->rule_value : $default;
    }
}
