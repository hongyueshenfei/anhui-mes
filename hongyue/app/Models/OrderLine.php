<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 订单明细 —— 对应架构 §3.3 order_lines 表
 *
 * 每行对应一个产品与数量；创建时同步生成 prod_tasks（生产任务）。
 */
class OrderLine extends Model
{
    /** 状态 */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';

    protected $fillable = [
        'order_id',
        'product_id',
        'qty',
        'delivery_date',
        'priority',
        'status',
        'done_qty',
        'mold_id',
        'line_no',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'product_id' => 'integer',
        'qty' => 'integer',
        'priority' => 'integer',
        'done_qty' => 'integer',
        'mold_id' => 'integer',
        'delivery_date' => 'date',
        'line_no' => 'integer',
    ];

    /**
     * 所属订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * 产品
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * 生产任务（产品级）
     */
    public function tasks()
    {
        return $this->hasMany(ProdTask::class);
    }

    /**
     * 状态中文名
     */
    public function getStatusLabelAttribute(): string
    {
        return ['pending' => '待排产', 'scheduled' => '已排产', 'in_progress' => '生产中', 'done' => '已完成'][$this->status] ?? $this->status;
    }
}