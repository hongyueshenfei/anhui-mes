<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 工序排程明细（甘特图数据源）—— 对应架构 §3.3 task_schedules 表
 *
 * - 成型工序 machine_id 必填；人工线工序排产线行（machine_id 落产线）；plan_end > plan_start
 * - source: manual/ai/insert/auto
 * - is_interrupted: 插单打断留档（已完成部分），剩余量重排新行
 * - is_overlap: 强制排入时允许重叠并显式标记
 * - op_name: 排程生成时冗余的工序名（独立快照），展示/重算不依赖 route_ops
 * - forecast_start/end: APS 预计起止（随报工滚动，初始=plan；plan 永不被自动修改）
 * - actual_start/end: 实际起止（报工推断：首笔正报工/累计合格达工序量）
 * - is_frozen: 时间窗冻结（当前起未来 N 天内不可直接手工重排）
 * - changeover_minutes: 排入时实际采用的换模分钟数（矩阵>工序>机台）
 */
class TaskSchedule extends Model
{
    /** 状态 */
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_DONE = 'done';

    /** 来源 */
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AI = 'ai';
    public const SOURCE_INSERT = 'insert';
    public const SOURCE_AUTO = 'auto';

    protected $fillable = [
        'task_id',
        'seq',
        'route_op_id',
        'op_type',
        'op_name',
        'machine_id',
        'mold_id',
        'plan_start',
        'plan_end',
        'qty',
        'status',
        'source',
        'is_interrupted',
        'done_qty',
        'is_overlap',
        'note',
        'forecast_start',
        'forecast_end',
        'actual_start',
        'actual_end',
        'is_frozen',
        'changeover_minutes',
    ];

    protected $casts = [
        'task_id' => 'integer',
        'seq' => 'integer',
        'route_op_id' => 'integer',
        'op_name' => 'string',
        'machine_id' => 'integer',
        'mold_id' => 'integer',
        'plan_start' => 'datetime',
        'plan_end' => 'datetime',
        'qty' => 'integer',
        'is_interrupted' => 'integer',
        'done_qty' => 'integer',
        'is_overlap' => 'integer',
        'forecast_start' => 'datetime',
        'forecast_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'is_frozen' => 'integer',
        'changeover_minutes' => 'integer',
    ];

    /**
     * 所属任务
     */
    public function task()
    {
        return $this->belongsTo(ProdTask::class, 'task_id');
    }

    /**
     * 工艺工序
     */
    public function routeOp()
    {
        return $this->belongsTo(RouteOp::class, 'route_op_id');
    }

    /**
     * 机台/产线行
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    /**
     * 模具
     */
    public function mold()
    {
        return $this->belongsTo(Mold::class, 'mold_id');
    }

    /**
     * 序列化为快照数组（版本快照用）
     */
    public function toSnapshotArray(): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'seq' => $this->seq,
            'route_op_id' => $this->route_op_id,
            'op_type' => $this->op_type,
            'op_name' => $this->op_name,
            'machine_id' => $this->machine_id,
            'mold_id' => $this->mold_id,
            'plan_start' => $this->plan_start?->format('Y-m-d H:i:s'),
            'plan_end' => $this->plan_end?->format('Y-m-d H:i:s'),
            'qty' => $this->qty,
            'status' => $this->status,
            'source' => $this->source,
            'is_interrupted' => $this->is_interrupted,
            'done_qty' => $this->done_qty,
            'is_overlap' => $this->is_overlap,
            'note' => $this->note,
            'forecast_start' => $this->forecast_start?->format('Y-m-d H:i:s'),
            'forecast_end' => $this->forecast_end?->format('Y-m-d H:i:s'),
            'actual_start' => $this->actual_start?->format('Y-m-d H:i:s'),
            'actual_end' => $this->actual_end?->format('Y-m-d H:i:s'),
            'is_frozen' => $this->is_frozen,
            'changeover_minutes' => $this->changeover_minutes,
        ];
    }
}
