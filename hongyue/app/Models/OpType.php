<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 工序类型字典（op_types）
 *
 * 工序类型从硬编码（RouteService::opTemplates）改为字典表维护：
 * - op_type 键被 route_ops.op_type 引用，编辑时不可改键（只允许改名称/属性）
 * - is_active=0 停用后不再出现在工艺编排下拉；历史 route_ops 不受影响
 * - workshop 决定机台/产线候选车间（与 Machine.workshop 枚举对齐）
 */
class OpType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'op_type',
        'op_name',
        'is_molding',
        'machine_type',
        'workshop',
        'sort',
        'is_active',
        'remark',
    ];

    protected $casts = [
        'is_molding' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * 是否被工艺路线引用（route_ops.op_type 使用次数）
     */
    public function usedCount(): int
    {
        return RouteOp::where('op_type', $this->op_type)->count();
    }
}
