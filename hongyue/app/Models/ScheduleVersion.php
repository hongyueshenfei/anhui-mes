<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 排产版本（回滚支撑）—— 对应架构 §3.3 schedule_versions 表
 *
 * - snapshot: {before: TaskSchedule[]快照, after: TaskSchedule[]快照}
 * - affected_orders: 插单受影响订单延期清单
 * - 支持：整版接受（ai）/局部采纳（ai partial）/回滚
 */
class ScheduleVersion extends Model
{
    /** 类型 */
    public const TYPE_MANUAL = 'manual';
    public const TYPE_AI = 'ai';
    public const TYPE_INSERT = 'insert';

    /** 状态 */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'version_no',
        'type',
        'status',
        'snapshot',
        'affected_orders',
        'biz_key',
        'created_by',
        'applied_at',
        'rolled_back_at',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'snapshot' => 'array',
        'affected_orders' => 'array',
        'created_by' => 'integer',
        'applied_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    /**
     * 操作人
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 类型中文名
     */
    public function getTypeLabelAttribute(): string
    {
        return ['manual' => '手工排产', 'ai' => '算法排产', 'insert' => '插单顺延'][$this->type] ?? $this->type;
    }

    /**
     * 状态中文名
     */
    public function getStatusLabelAttribute(): string
    {
        return ['draft' => '草稿', 'applied' => '已生效', 'rolled_back' => '已回滚'][$this->status] ?? $this->status;
    }
}
