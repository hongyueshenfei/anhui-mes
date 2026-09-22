<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 换模时间矩阵（APS一期）
 *
 * 按「前序模具 → 当前模具」记录换模耗时与禁止切换（如黑料→白料需清机）。
 * 解析优先级见 ChangeoverService：本矩阵 > route_ops.mold_change_min > machines.mold_change_min > 0
 */
class MoldChangeMatrix extends Model
{
    use SoftDeletes;

    protected $table = 'mold_change_matrices';

    protected $fillable = [
        'from_mold_id',
        'to_mold_id',
        'changeover_minutes',
        'is_forbidden',
        'remark',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'from_mold_id' => 'integer',
        'to_mold_id' => 'integer',
        'changeover_minutes' => 'integer',
        'is_forbidden' => 'integer',
    ];

    /**
     * 前序模具
     */
    public function fromMold()
    {
        return $this->belongsTo(Mold::class, 'from_mold_id');
    }

    /**
     * 当前模具
     */
    public function toMold()
    {
        return $this->belongsTo(Mold::class, 'to_mold_id');
    }
}
