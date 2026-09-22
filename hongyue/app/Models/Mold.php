<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 模具台账
 *
 * 对应架构 §3.2 molds 表：
 * - cavity_count 模穴数（寿命折算：开模次数 = 报工数 ÷ 模穴数）
 * - lifetime_openings 设计寿命（额定开模次数）
 * - opened_count 累计开模次数（三期望报工事件累加）
 * - next_maintain_at 保养提醒（到期提醒）
 */
class Mold extends Model
{
    use SoftDeletes;

    /** 模具类型 */
    public const TYPE_INJECTION = 'injection';
    public const TYPE_BLOW = 'blow';

    /** 状态 */
    public const STATUS_IN_USE = 'in_use';
    public const STATUS_IDLE = 'idle';
    public const STATUS_REPAIR = 'repair';
    public const STATUS_SCRAPPED = 'scrapped';

    public const STATUS_LABELS = [
        self::STATUS_IN_USE => '使用中',
        self::STATUS_IDLE => '闲置',
        self::STATUS_REPAIR => '维修中',
        self::STATUS_SCRAPPED => '已报废',
    ];

    protected $fillable = [
        'code',
        'name',
        'type',
        'status',
        'location',
        'cavity_count',
        'lifetime_openings',
        'opened_count',
        'purchase_cost',
        'purchase_date',
        'vendor',
        'maintain_cycle_days',
        'last_maintain_at',
        'next_maintain_at',
        'notes',
    ];

    protected $casts = [
        'cavity_count' => 'integer',
        'lifetime_openings' => 'integer',
        'opened_count' => 'integer',
        'purchase_cost' => 'float',
        'purchase_date' => 'date',
        'maintain_cycle_days' => 'integer',
        'last_maintain_at' => 'datetime',
        'next_maintain_at' => 'datetime',
    ];

    /**
     * 关联产品（多对多）
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'mold_product')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /**
     * 适用机台（多对多）
     */
    public function machines()
    {
        return $this->belongsToMany(Machine::class, 'mold_machines')->withTimestamps();
    }

    /**
     * 保养/维修记录
     */
    public function maintainRecords()
    {
        return $this->hasMany(MoldMaintainRecord::class)->orderByDesc('at');
    }

    /**
     * 寿命流水
     */
    public function lifeLogs()
    {
        return $this->hasMany(MoldLifeLog::class)->orderByDesc('created_at');
    }

    /**
     * 状态中文名
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * 剩余寿命（次）
     */
    public function getRemainingOpeningsAttribute(): int
    {
        return max(0, (int) $this->lifetime_openings - (int) $this->opened_count);
    }

    /**
     * 是否到期保养（今日 ≥ next_maintain_at）
     */
    public function isMaintainDue(): bool
    {
        return $this->next_maintain_at !== null && $this->next_maintain_at->isPast();
    }

    /**
     * 指定机台是否适用（三匹配校验基础）
     */
    public function isCompatibleWithMachine(int $machineId): bool
    {
        return $this->machines()->whereKey($machineId)->exists();
    }
}