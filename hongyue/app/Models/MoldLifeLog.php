<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 模具寿命流水（只增不改）
 *
 * 对应架构 §3.2 mold_life_logs 表：
 * - opening_count = 合格数 ÷ 模穴数（向上取整）
 * - 三期望报工落库事件联动写入；一期仅提供查询与计算函数
 */
class MoldLifeLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'mold_id',
        'task_id',
        'work_report_id',
        'opening_count',
        'created_at',
    ];

    protected $casts = [
        'mold_id' => 'integer',
        'task_id' => 'integer',
        'work_report_id' => 'integer',
        'opening_count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function mold()
    {
        return $this->belongsTo(Mold::class);
    }

    /**
     * 关联生产任务（订单号溯源：task → line → order）
     */
    public function task()
    {
        return $this->belongsTo(ProdTask::class, 'task_id');
    }
}
