<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 物料主数据 —— 对应 materials 表（四期物料同步 K4）
 *
 * 编码唯一（对齐金蝶物料编码）；产品档案 products.code 应能在本表找到，
 * 未匹配项在集成配置页给出提示清单。
 */
class Material extends Model
{
    protected $fillable = [
        'code',
        'name',
        'spec',
        'unit',
        'category',
        'status',
        'k3_updated_at',
    ];

    protected $casts = [
        'k3_updated_at' => 'datetime',
    ];

    /**
     * 按编码 upsert（物料同步幂等）
     */
    public static function upsertByCode(array $item): Material
    {
        return self::updateOrCreate(
            ['code' => $item['code']],
            [
                'name' => $item['name'] ?? '',
                'spec' => $item['spec'] ?? null,
                'unit' => $item['unit'] ?? null,
                'category' => $item['category'] ?? null,
                'status' => $item['status'] ?? 'active',
                'k3_updated_at' => $item['k3_updated_at'] ?? null,
            ]
        );
    }
}
