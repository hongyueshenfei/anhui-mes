<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 工艺路线工序 —— 排产与报价共用的工艺参数源
 *
 * 对应架构 §3.2 route_ops 表：
 * - seq 工序顺序（路线内连续 1/2/3/...），UNIQUE(route_id, seq)
 * - op_type: molding_injection/molding_blow/paint/effect/pack
 * - workshop_id 生产车间（车间实体化后显式选择；NULL=按工序类型字典推导）
 * - 理论产能不落工艺路线：排产选定机台后按机台产能计算（machines.capacity_per_hour）
 * - rate_key 报价费率键
 */
class RouteOp extends Model
{
    /** 工序类型常量 */
    public const OP_MOLDING_INJECTION = 'molding_injection';
    public const OP_MOLDING_BLOW = 'molding_blow';
    public const OP_PAINT = 'paint';
    public const OP_EFFECT = 'effect';
    public const OP_PACK = 'pack';

    public const OP_LABELS = [
        self::OP_MOLDING_INJECTION => '注塑成型',
        self::OP_MOLDING_BLOW => '吹塑成型',
        self::OP_PAINT => '喷漆',
        self::OP_EFFECT => '效果',
        self::OP_PACK => '包装',
    ];

    protected $fillable = [
        'route_id',
        'seq',
        'op_type',
        'op_name',
        'workshop_id',
        'mold_change_min',
        'shift_mode',
        'machine_type',
        'rate_key',
        'default_machine_id',
        'default_line_id',
        'std_hours',
        'owner_id',
        'remark',
    ];

    protected $casts = [
        'route_id' => 'integer',
        'seq' => 'integer',
        'workshop_id' => 'integer',
        'mold_change_min' => 'integer',
        'std_hours' => 'float',
        'owner_id' => 'integer',
    ];

    /**
     * 所属工艺路线
     */
    public function route()
    {
        return $this->belongsTo(PlmRoute::class, 'route_id');
    }

    /**
     * 生产车间（车间实体化后显式选择；NULL=按工序类型字典推导）
     */
    public function workshop()
    {
        return $this->belongsTo(Workshop::class, 'workshop_id');
    }

    /**
     * 工序默认机台（成型工序：注塑/吹塑 必选）
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class, 'default_machine_id');
    }

    /**
     * 工序默认产线（人工线工序：喷漆/效果/包装 必选，machines 表 line 行）
     */
    public function line()
    {
        return $this->belongsTo(Machine::class, 'default_line_id');
    }

    /**
     * 工序负责人（users.owner_id）
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * 工序类型中文名
     */
    public function getOpTypeLabelAttribute(): string
    {
        return self::OP_LABELS[$this->op_type] ?? $this->op_type;
    }

    /**
     * 是否成型工序（需要机台/模具）
     */
    public function isMoldingOp(): bool
    {
        return in_array($this->op_type, [self::OP_MOLDING_INJECTION, self::OP_MOLDING_BLOW], true);
    }

    /**
     * 工序目标资源类型（客户确认「资源强对应」统一语义）
     *
     * - injection / blow：成型工序（取 machine_type）
     * - paint_line：喷漆工序（对应喷漆产线）
     * - effect_line：效果工序（对应效果产线）
     * - pack_line：包装工序（对应包装产线）
     *
     * 排产时产线行以产线机器绑定的车间（workshop_id）为资源归属。
     */
    public function resourceType(): ?string
    {
        return match ($this->op_type) {
            self::OP_MOLDING_INJECTION => \App\Models\Machine::TYPE_INJECTION,
            self::OP_MOLDING_BLOW => \App\Models\Machine::TYPE_BLOW,
            self::OP_PAINT => \App\Models\Machine::TYPE_PAINT_LINE,
            self::OP_EFFECT => \App\Models\Machine::TYPE_EFFECT_LINE,
            self::OP_PACK => \App\Models\Machine::TYPE_PACK_LINE,
            default => $this->machine_type,
        };
    }

    /**
     * 工序类型 → 适用车间（intrinsic 映射，不落库）
     *
     * 客户规则：每个工艺只能在能做该工艺的车间里选机台/产线。
     * 该映射由 op_type 派生，与 Machine.workshop 字段对齐。
     */
    public static function workshopForOpType(string $opType): ?string
    {
        return match ($opType) {
            self::OP_MOLDING_INJECTION => 'injection',
            self::OP_MOLDING_BLOW => 'blow',
            self::OP_PAINT => 'paint',
            self::OP_EFFECT => 'effect',
            self::OP_PACK => 'pack',
            default => null,
        };
    }

    /**
     * 工序类型 → 目标资源类型（静态版，便于在 service 中按 op_type 校验机台/产线类型）
     *
     * 语义与实例方法 resourceType() 保持一致：
     * - injection / blow：成型机台
     * - paint_line：喷漆产线
     * - effect_line：效果产线
     * - pack_line：包装产线
     */
    public static function resourceTypeFor(string $opType): ?string
    {
        return match ($opType) {
            self::OP_MOLDING_INJECTION => Machine::TYPE_INJECTION,
            self::OP_MOLDING_BLOW => Machine::TYPE_BLOW,
            self::OP_PAINT => Machine::TYPE_PAINT_LINE,
            self::OP_EFFECT => Machine::TYPE_EFFECT_LINE,
            self::OP_PACK => Machine::TYPE_PACK_LINE,
            default => null,
        };
    }

    /**
     * 资源类型中文名
     */
    public function getResourceTypeLabelAttribute(): string
    {
        $type = $this->resourceType();

        return \App\Models\Machine::TYPE_LABELS[$type] ?? ($type ?? '未配置');
    }
}
