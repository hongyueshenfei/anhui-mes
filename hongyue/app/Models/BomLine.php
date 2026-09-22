<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BOM 明细
 *
 * 对应架构 §3.2 bom_lines 表：
 * - item_code 材料编码（对齐金蝶物料）；is_key=1 为主材（报价材料成本取主材）
 */
class BomLine extends Model
{
    protected $fillable = [
        'bom_id',
        'seq',
        'item_code',
        'item_name',
        'qty',
        'unit',
        'loss_rate',
        'is_key',
    ];

    protected $casts = [
        'bom_id' => 'integer',
        'seq' => 'integer',
        'qty' => 'float',
        'loss_rate' => 'float',
        'is_key' => 'integer',
    ];

    /**
     * 所属 BOM
     */
    public function bom()
    {
        return $this->belongsTo(Bom::class);
    }
}