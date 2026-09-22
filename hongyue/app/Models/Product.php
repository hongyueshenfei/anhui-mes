<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 产品档案
 *
 * 对应架构 §3.2 products 表：
 * - code 与金蝶物料编码一致（金蝶为主映射）
 * - status: developing(开发)/trial(试产)/mass(量产)/obsolete(停产)
 * - default_mold_id / default_route_id 默认模具与工艺路线
 *   （因与 molds/routes 存在循环引用，仅建索引不加 DB 外键，应用层校验）
 */
class Product extends Model
{
    use SoftDeletes;

    /** 状态常量 */
    public const STATUS_DEVELOPING = 'developing';
    public const STATUS_TRIAL = 'trial';
    public const STATUS_MASS = 'mass';
    public const STATUS_OBSOLETE = 'obsolete';

    public const STATUS_LABELS = [
        self::STATUS_DEVELOPING => '开发中',
        self::STATUS_TRIAL => '试产',
        self::STATUS_MASS => '量产',
        self::STATUS_OBSOLETE => '停产',
    ];

    /** 合法状态流转：开发→试产→量产；停产可由任意状态进入 */
    public const STATUS_FLOW = [
        self::STATUS_DEVELOPING => [self::STATUS_TRIAL, self::STATUS_OBSOLETE],
        self::STATUS_TRIAL => [self::STATUS_MASS, self::STATUS_OBSOLETE],
        self::STATUS_MASS => [self::STATUS_OBSOLETE],
        self::STATUS_OBSOLETE => [],
    ];

    protected $fillable = [
        'code',
        'name',
        'spec',
        'unit',
        'status',
        'default_mold_id',
        'default_route_id',
        'weight',
        'required_tonnage',
    ];

    protected $casts = [
        'default_mold_id' => 'integer',
        'default_route_id' => 'integer',
        'weight' => 'float',
        'required_tonnage' => 'integer',
        // produced_qty 由 Controller::index selectSub 注入，历史生产累计
        'produced_qty' => 'integer',
    ];

    /**
     * BOM 列表（按版本倒序）
     */
    public function boms()
    {
        return $this->hasMany(Bom::class)->orderByDesc('version');
    }

    /**
     * 默认模具
     */
    public function defaultMold()
    {
        return $this->belongsTo(Mold::class, 'default_mold_id');
    }

    /**
     * 默认工艺路线
     */
    public function defaultRoute()
    {
        return $this->belongsTo(PlmRoute::class, 'default_route_id');
    }

    /**
     * 关联模具（多对多）
     */
    public function molds()
    {
        return $this->belongsToMany(Mold::class, 'mold_product')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * 状态中文名
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * 校验状态流转是否合法
     */
    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::STATUS_FLOW[$from] ?? [], true);
    }

    /**
     * 获取已发布的最新 BOM（未发布版本不可被引用，一期验收 A8）
     */
    public function getPublishedBom(): ?Bom
    {
        return $this->boms()->where('status', Bom::STATUS_PUBLISHED)->first();
    }

    /**
     * 获取默认工艺路线（仅已发布可被引用）
     *
     * 工艺路线为独立实体，产品通过 default_route_id 引用；
     * 未设置或未发布时返回 null（排产前必须设置已发布路线）。
     */
    public function getPublishedRoute(): ?PlmRoute
    {
        if (! $this->default_route_id) {
            return null;
        }

        $route = PlmRoute::find($this->default_route_id);

        return $route && $route->isPublished() ? $route : null;
    }
}
