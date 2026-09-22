<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 报工流水（只增不改）—— 对应架构 §3.4 work_reports 表
 *
 * - 仅 INSERT；更正用冲减负记录（sign=-1 且 remark 必填）
 * - qty 合格数 / defect_qty 次品数（计件按合格数）
 */
class WorkReport extends Model
{
    /** 来源 */
    public const SOURCE_SCAN = 'scan';
    public const SOURCE_CLICK = 'click';
    public const SOURCE_LEADER_REPORT = 'leader_report';
    public const SOURCE_MAKEUP = 'makeup';
    public const SOURCE_OFFLINE = 'offline';
    public const SOURCE_MACHINE_QR = 'machine_qr';
    public const SOURCE_LEADER_BATCH = 'leader_batch';

    protected $fillable = [
        'task_id',
        'schedule_id',
        'product_id',
        'machine_id',
        'qty',
        'defect_qty',
        'sign',
        'report_by',
        'worker_id',
        'report_time',
        'start_time',
        'end_time',
        'work_date',
        'shift_code',
        'client_request_id',
        'source',
        'offline_flag',
        'remark',
    ];

    protected $casts = [
        'task_id' => 'integer',
        'schedule_id' => 'integer',
        'product_id' => 'integer',
        'machine_id' => 'integer',
        'qty' => 'integer',
        'defect_qty' => 'integer',
        'sign' => 'integer',
        'report_by' => 'integer',
        'worker_id' => 'integer',
        'offline_flag' => 'integer',
        'report_time' => 'datetime',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'work_date' => 'date:Y-m-d',
    ];

    /**
     * JSON 序列化时间统一输出本地 Y-m-d H:i:s（不做 UTC 转换，避免报工时间偏移 8 小时）
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public function task()
    {
        return $this->belongsTo(ProdTask::class, 'task_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class, 'machine_id');
    }

    /**
     * 报工所属工序（排程段）；任务级报工（未指定工序）为 null
     */
    public function schedule()
    {
        return $this->belongsTo(TaskSchedule::class, 'schedule_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'report_by');
    }

    public function worker()
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    /**
     * 净合格数（正负相抵）
     */
    public function getNetQtyAttribute(): int
    {
        // qty 已按正负直接记录正常报工/冲减，sign 仅保留操作类型和筛选兼容。
        return (int) $this->qty;
    }

    /**
     * 来源中文名
     */
    public function getSourceLabelAttribute(): string
    {
        return [
            self::SOURCE_SCAN => '扫码',
            self::SOURCE_CLICK => '点选',
            self::SOURCE_LEADER_REPORT => '班组长代报',
            self::SOURCE_MAKEUP => '补报',
            self::SOURCE_OFFLINE => '离线补传',
            self::SOURCE_MACHINE_QR => '机台扫码',
            self::SOURCE_LEADER_BATCH => '班组长/统计员日结',
        ][$this->source] ?? $this->source;
    }
}
