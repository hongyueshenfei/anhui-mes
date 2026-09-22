<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 报价明细行（架构 §3.6 quote_lines 表）—— 五期
 *
 * 成本构成（单位成本，可展开）：material_cost 材料 / process_cost 加工 /
 * mold_amort 模具摊销 / loss_cost 损耗；suggest_price 建议单价；
 * anomaly_flag 偏离历史成交 ±30%；ai_assist LLM 辅助状态。
 */
class QuoteLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'quote_id',
        'spec_text',
        'qty',
        'ref_product_id',
        'product_id',
        'material_cost',
        'process_cost',
        'mold_amort',
        'loss_cost',
        'total_cost',
        'margin',
        'suggest_price',
        'delivery_days',
        'llm_remark',
        'ai_assist',
        'anomaly_flag',
    ];

    protected $casts = [
        'quote_id' => 'integer',
        'qty' => 'integer',
        'ref_product_id' => 'integer',
        'product_id' => 'integer',
        'material_cost' => 'float',
        'process_cost' => 'float',
        'mold_amort' => 'float',
        'loss_cost' => 'float',
        'total_cost' => 'float',
        'margin' => 'float',
        'suggest_price' => 'float',
        'delivery_days' => 'integer',
        'anomaly_flag' => 'boolean',
    ];

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }

    public function refProduct()
    {
        return $this->belongsTo(Product::class, 'ref_product_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
