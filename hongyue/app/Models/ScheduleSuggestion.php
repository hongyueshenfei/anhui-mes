<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 排程建议（APS一期：建议层）
 *
 * 报工滞后/破交期时系统生成顺延建议，计划员确认后采纳。
 * 采纳走 ManualScheduleService 正常保存链路（版本快照+锁定），
 * 采纳后计划员仍可手动调整——手工排产铁保底。
 */
class ScheduleSuggestion extends Model
{
    /** 建议类型 */
    public const TYPE_POSTPONE = 'postpone';

    /** 状态 */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'type',
        'trigger_report_id',
        'task_id',
        'affected',
        'delayed_orders',
        'reason',
        'status',
        'processed_by',
        'processed_at',
        'created_by',
    ];

    protected $casts = [
        'trigger_report_id' => 'integer',
        'task_id' => 'integer',
        'affected' => 'array',
        'delayed_orders' => 'array',
        'processed_by' => 'integer',
        'processed_at' => 'datetime',
    ];

    /**
     * 主受影响任务
     */
    public function task()
    {
        return $this->belongsTo(ProdTask::class, 'task_id');
    }

    /**
     * 触发报工流水
     */
    public function triggerReport()
    {
        return $this->belongsTo(WorkReport::class, 'trigger_report_id');
    }
}
