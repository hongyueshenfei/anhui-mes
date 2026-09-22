<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 车间（workshops）—— 车间实体化（用户需求：先有车间，车间下面是机台或产线）
 *
 * 对应架构 §3.1：机台/产线挂 workshop_id；班组概念下线后车间是资源归属的顶层。
 * code 与既有枚举一致：injection/blow/paint/effect/pack。
 */
class Workshop extends Model
{
    use SoftDeletes;

    public const CODE_INJECTION = 'injection';
    public const CODE_BLOW = 'blow';
    public const CODE_PAINT = 'paint';
    public const CODE_EFFECT = 'effect';
    public const CODE_PACK = 'pack';

    /** 车间中文标签 */
    public const NAME_LABELS = [
        self::CODE_INJECTION => '注塑车间',
        self::CODE_BLOW => '吹塑车间',
        self::CODE_PAINT => '喷漆车间',
        self::CODE_EFFECT => '效果车间',
        self::CODE_PACK => '包装车间',
    ];

    protected $fillable = [
        'code',
        'name',
        'sort',
        'is_active',
        'remark',
    ];

    protected $casts = [
        'sort' => 'integer',
        'is_active' => 'integer',
    ];

    /** 该车间下的机台/产线 */
    public function machines()
    {
        return $this->hasMany(Machine::class);
    }

    /** 是否启用 */
    public function isActive(): bool
    {
        return (int) $this->is_active === 1;
    }
}
