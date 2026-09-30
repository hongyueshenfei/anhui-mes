<?php

namespace App\Models;

use App\Models\WorkOrderLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 订单头 —— 对应架构 §3.3 orders 表
 *
 * - src: manual(手工)/excel(导入)/mini_program/k3(四期拉单)
 * - status: route_confirm(工艺确认)/pending(待排产)/scheduled(已排产)/in_progress(生产中)
 *          /paused(已停工)/produced(已入库)/aborted(已中止)/closed(已关闭)
 * - priority 0-5，数值越小越急（P0 最紧急）
 */
class Order extends Model
{
    use SoftDeletes;

    /** 来源 */
    public const SRC_MANUAL = 'manual';
    public const SRC_EXCEL = 'excel';
    public const SRC_MINI_PROGRAM = 'mini_program';
    public const SRC_K3 = 'k3';

    /** 状态 */
    public const STATUS_ROUTE_CONFIRM = 'route_confirm';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_PRODUCED = 'produced';
    public const STATUS_ABORTED = 'aborted';
    public const STATUS_CLOSED = 'closed';

    public const STATUS_LABELS = [
        self::STATUS_ROUTE_CONFIRM => '工艺确认',
        self::STATUS_PENDING => '待排产',
        self::STATUS_SCHEDULED => '已排产',
        self::STATUS_IN_PROGRESS => '生产中',
        self::STATUS_PAUSED => '已停工',
        self::STATUS_PRODUCED => '已入库',
        self::STATUS_ABORTED => '已中止',
        self::STATUS_CLOSED => '已关闭',
    ];

    protected $fillable = [
        'order_no',
        'src',
        'k3_bill_no',
        'customer_name',
        'status',
        'priority',
        'delivery_date',
        'remark',
        'created_by',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'priority' => 'integer',
        'delivery_date' => 'date',
        'created_by' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * 订单明细
     */
    public function lines()
    {
        return $this->hasMany(OrderLine::class)->orderBy('line_no');
    }

    /**
     * 创建人
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * 状态中文名
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * 工单操作日志
     */
    public function logs()
    {
        return $this->hasMany(WorkOrderLog::class)->orderByDesc('id');
    }

    /**
     * 订单是否含生产中的任务（用于状态聚合）
     */
    public function hasInProgressTask(): bool
    {
        return $this->lines()->whereHas('tasks', fn ($q) => $q->whereIn('status', ['in_progress', 'paused']))->exists();
    }
}
