<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 生产入库单（分批入库）—— 对应架构 §3.4 stock_ins 表
 *
 * - ins_no 每批独立唯一
 * - k3_push_status: queued/pushing/success/failed/retry
 */
class StockIn extends Model
{
    /** 单据状态 */
    public const INS_STATUS_DRAFT = 'draft';
    public const INS_STATUS_CONFIRMED = 'confirmed';
    public const INS_STATUS_PUSHED = 'pushed';

    /** K3 推送状态 */
    public const K3_QUEUED = 'queued';
    public const K3_PUSHING = 'pushing';
    public const K3_SUCCESS = 'success';
    public const K3_FAILED = 'failed';
    public const K3_RETRY = 'retry';

    protected $fillable = [
        'ins_no',
        'task_id',
        'product_id',
        'qty',
        'ins_status',
        'k3_push_status',
        'k3_bill_no',
        'k3_result',
        'created_by',
        'remark',
    ];

    protected $casts = [
        'task_id' => 'integer',
        'product_id' => 'integer',
        'qty' => 'integer',
        'created_by' => 'integer',
        'k3_result' => 'array',
    ];

    public function task()
    {
        return $this->belongsTo(ProdTask::class, 'task_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getK3PushStatusLabelAttribute(): string
    {
        return [
            self::K3_QUEUED => '待推送',
            self::K3_PUSHING => '推送中',
            self::K3_SUCCESS => '已推送',
            self::K3_FAILED => '推送失败',
            self::K3_RETRY => '重试中',
        ][$this->k3_push_status] ?? $this->k3_push_status;
    }
}