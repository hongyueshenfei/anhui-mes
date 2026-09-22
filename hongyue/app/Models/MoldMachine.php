<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 模具-适用机台 多对多关联（Pivot）
 */
class MoldMachine extends Model
{
    protected $table = 'mold_machines';

    public $timestamps = true;

    protected $fillable = [
        'mold_id',
        'machine_id',
    ];

    protected $casts = [
        'mold_id' => 'integer',
        'machine_id' => 'integer',
    ];

    public function mold()
    {
        return $this->belongsTo(Mold::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
}