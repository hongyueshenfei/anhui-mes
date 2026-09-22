<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 材料价格（架构 §3.6 material_prices 表）—— 五期 E1
 *
 * UNIQUE(material_code, effective_date)：生效日期版本化。
 * 报价时取 effective_date ≤ 报价日期的最近一条；修改后新估价取新价，历史报价不受影响
 * （报价单落库成本快照）。
 */
class MaterialPrice extends Model
{
    protected $fillable = [
        'material_code',
        'material_name',
        'price_per_unit',
        'unit',
        'effective_date',
        'source',
    ];

    protected $casts = [
        'price_per_unit' => 'float',
        'effective_date' => 'date',
    ];

    /**
     * 取某材料在指定日期的生效价（无则 null）
     */
    public static function priceAt(string $materialCode, ?string $date = null): ?self
    {
        $date = $date ?? now()->toDateString();

        return self::where('material_code', $materialCode)
            ->whereDate('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();
    }
}
