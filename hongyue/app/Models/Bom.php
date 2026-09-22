<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BOM 头
 *
 * 对应架构 §3.2 boms 表：
 * - UNIQUE(product_id, version)
 * - status: draft(草稿)/published(已发布)/disabled(已禁用)
 * - k3_push_status: none/queued/pushed/failed（四期接金蝶启用）
 */
class Bom extends Model
{
    use SoftDeletes;

    /** 状态常量 */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISABLED = 'disabled';

    /** 金蝶推送状态（四期启用） */
    public const K3_NONE = 'none';
    public const K3_QUEUED = 'queued';
    public const K3_PUSHED = 'pushed';
    public const K3_FAILED = 'failed';

    protected $fillable = [
        'product_id',
        'version',
        'status',
        'k3_push_status',
        'published_at',
        'published_by',
        'disabled_at',
        'disabled_by',
        'revoked_at',
        'revoked_by',
        'remark',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'published_by' => 'integer',
        'disabled_by' => 'integer',
        'revoked_by' => 'integer',
        'published_at' => 'datetime',
        'disabled_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * BOM 明细
     */
    public function lines()
    {
        return $this->hasMany(BomLine::class)->orderBy('seq');
    }

    /**
     * 所属产品
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * 发布人
     */
    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * 禁用操作人
     */
    public function disabler()
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    /**
     * 退回操作人
     */
    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * 是否已发布
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * 是否已禁用
     */
    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }
}