<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 报价单头（架构 §3.6 quotes 表）—— 五期
 *
 * - status: draft(草稿)/sent(已发)/won(成交)/lost(未成交)
 * - target_margin 目标毛利率；suggest_delivery 建议交期（联动产能负荷粗算）
 * - final_price 成交价；created_by 业务员；调价走 audit（quote_price_change）
 */
class Quote extends Model
{
    /** 状态 */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_WON = 'won';
    public const STATUS_LOST = 'lost';

    /** 来源 */
    public const SOURCE_SALES = 'sales';
    public const SOURCE_MINI_PROGRAM = 'mini_program';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT => '草稿',
        self::STATUS_SENT => '已发',
        self::STATUS_WON => '成交',
        self::STATUS_LOST => '未成交',
    ];

    protected $fillable = [
        'quote_no',
        'customer_name',
        'source',
        'status',
        'target_margin',
        'suggest_delivery',
        'final_price',
        'created_by',
        'remark',
    ];

    protected $casts = [
        'target_margin' => 'float',
        'suggest_delivery' => 'date',
        'final_price' => 'float',
        'created_by' => 'integer',
    ];

    public function lines()
    {
        return $this->hasMany(QuoteLine::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
