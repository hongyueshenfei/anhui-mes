<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 工艺路线头
 *
 * 工艺路线为独立实体（路线库），不与产品绑定：
 * 对应架构 §3.2 routes 表：UNIQUE(name, version)、status(draft/published)
 * 类名 PlmRoute 避免与 Illuminate\Routing\Route / Facades\Route 混淆。
 */
class PlmRoute extends Model
{
    use SoftDeletes;

    /** 状态常量 */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISABLED = 'disabled';

    protected $table = 'routes';

    protected $fillable = [
        'name',
        'version',
        'status',
        'remark',
    ];

    protected $casts = [
    ];

    /**
     * 工序序列（按 seq 排序）
     */
    public function ops()
    {
        return $this->hasMany(RouteOp::class, 'route_id')->orderBy('seq');
    }

    /**
     * 是否已发布
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * 是否已禁用（禁用后不可被排产/引用，需启用或下架后重新发布）
     */
    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }
}
