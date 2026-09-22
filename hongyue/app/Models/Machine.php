<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 机台档案 / 产线资源（客户确认「资源强对应」新业务规则）
 *
 * 对应架构 §3.1 machines 表：
 * - 如 I-01（注塑 1 号）、B-02（吹塑 2 号）
 * - P-01 喷漆产线（type=paint_line）、P-02 效果产线（type=effect_line）、P-03 包装产线（type=pack_line）：
 *   人工线资源，排产/报工按产线行承载，资源归属按 workshop_id（车间）
 * - capacity_per_hour 机台侧默认标准产能（件/小时），产品工艺为空时兜底
 * - mold_change_min 机台侧默认换模分钟数
 */
class Machine extends Model
{
    use SoftDeletes;

    /** 机台类型 */
    public const TYPE_INJECTION = 'injection';
    public const TYPE_BLOW = 'blow';
    public const TYPE_PAINT_LINE = 'paint_line';
    public const TYPE_EFFECT_LINE = 'effect_line';
    public const TYPE_PACK_LINE = 'pack_line';

    /** 成型机台类型（参与模具/三匹配） */
    public const MOLDING_TYPES = [self::TYPE_INJECTION, self::TYPE_BLOW];

    /** 产线资源类型（人工线，绑班组） */
    public const LINE_TYPES = [self::TYPE_PAINT_LINE, self::TYPE_EFFECT_LINE, self::TYPE_PACK_LINE];

    /** 全部资源类型 */
    public const RESOURCE_TYPES = [self::TYPE_INJECTION, self::TYPE_BLOW, self::TYPE_PAINT_LINE, self::TYPE_EFFECT_LINE, self::TYPE_PACK_LINE];

    public const TYPE_LABELS = [
        self::TYPE_INJECTION => '注塑机',
        self::TYPE_BLOW => '吹塑机',
        self::TYPE_PAINT_LINE => '喷漆产线',
        self::TYPE_EFFECT_LINE => '效果产线',
        self::TYPE_PACK_LINE => '包装产线',
    ];

    /** 状态 */
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_MAINTENANCE = 'maintenance';

    protected $fillable = [
        'code',
        'name',
        'type',
        'workshop_id',
        'capacity_per_hour',
        'mold_change_min',
        'tonnage',
        'shift_mode',
        'status',
        'is_active',
    ];

    protected $casts = [
        'capacity_per_hour' => 'float',
        'mold_change_min' => 'integer',
        'tonnage' => 'integer',
        'workshop_id' => 'integer',
        'is_active' => 'integer',
    ];

    /** 序列化附加字段：车间名（前端机台页展示） */
    protected $appends = ['workshop_name'];

    /**
     * 模具-机台 多对多（适用机台）
     */
    public function molds()
    {
        return $this->belongsToMany(Mold::class, 'mold_machines')->withTimestamps();
    }

    /**
     * 所属车间（车间实体化：先有车间，车间下面是机台/产线）
     */
    public function workshop()
    {
        return $this->belongsTo(Workshop::class, 'workshop_id');
    }

    /** 当前资源上的工序排程，用于停用前引用保护与遗留资源清理 */
    public function schedules()
    {
        return $this->hasMany(TaskSchedule::class, 'machine_id');
    }

    /**
     * 是否成型机台（injection/blow）
     */
    public function isMoldingMachine(): bool
    {
        return in_array($this->type, self::MOLDING_TYPES, true);
    }

    /**
     * 是否产线资源（paint_line/effect_line/pack_line）
     */
    public function isLine(): bool
    {
        return in_array($this->type, self::LINE_TYPES, true);
    }

    /**
     * 资源类型中文名（前端展示/报错用）
     */
    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /**
     * 状态中文名（排产校验/前端提示共用）
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_RUNNING => '运行中',
            self::STATUS_STOPPED => '已停用',
            self::STATUS_MAINTENANCE => '维修中',
            default => (string) ($this->status ?: '未设置'),
        };
    }

    /**
     * 车间名（车间实体化后取 workshops.name）
     */
    public function getWorkshopNameAttribute(): ?string
    {
        return $this->workshop?->name;
    }
}
