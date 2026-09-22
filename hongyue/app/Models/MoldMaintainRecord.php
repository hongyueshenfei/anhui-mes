<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 模具保养/维修记录
 *
 * 对应架构 §3.2 mold_maintain_records 表：type(maintain/repair)
 */
class MoldMaintainRecord extends Model
{
    /** 类型常量 */
    public const TYPE_MAINTAIN = 'maintain';
    public const TYPE_REPAIR = 'repair';

    protected $fillable = [
        'mold_id',
        'type',
        'at',
        'cost',
        'vendor',
        'remark',
    ];

    protected $casts = [
        'mold_id' => 'integer',
        'at' => 'datetime',
        'cost' => 'float',
    ];

    public function mold()
    {
        return $this->belongsTo(Mold::class);
    }
}