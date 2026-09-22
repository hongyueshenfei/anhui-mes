<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 模具-产品 多对多关联（Pivot）
 */
class MoldProduct extends Model
{
    protected $table = 'mold_product';

    public $timestamps = true;

    protected $fillable = [
        'mold_id',
        'product_id',
        'is_default',
    ];

    protected $casts = [
        'mold_id' => 'integer',
        'product_id' => 'integer',
        'is_default' => 'integer',
    ];

    public function mold()
    {
        return $this->belongsTo(Mold::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}