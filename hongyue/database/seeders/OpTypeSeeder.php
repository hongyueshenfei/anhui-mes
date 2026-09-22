<?php

namespace Database\Seeders;

use App\Models\OpType;
use Illuminate\Database\Seeder;

/**
 * 工序类型字典种子数据（与既有 RouteOp::OP_* 常量对齐）
 *
 * workshop 语义与 RouteOp::workshopForOpType() 一致：
 * injection/blow/paint/effect/pack（Machine.workshop 枚举）。
 */
class OpTypeSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['op_type' => 'molding_injection', 'op_name' => '注塑成型', 'is_molding' => 1, 'machine_type' => 'injection', 'workshop' => 'injection', 'sort' => 1, 'is_active' => 1, 'remark' => '注塑机成型，需机台+换模'],
            ['op_type' => 'molding_blow', 'op_name' => '吹塑成型', 'is_molding' => 1, 'machine_type' => 'blow', 'workshop' => 'blow', 'sort' => 2, 'is_active' => 1, 'remark' => '吹塑机成型，需机台+换模'],
            ['op_type' => 'paint', 'op_name' => '喷漆', 'is_molding' => 0, 'machine_type' => null, 'workshop' => 'paint', 'sort' => 3, 'is_active' => 1, 'remark' => '喷漆线人工工序'],
            ['op_type' => 'effect', 'op_name' => '效果处理', 'is_molding' => 0, 'machine_type' => null, 'workshop' => 'effect', 'sort' => 4, 'is_active' => 1, 'remark' => '效果车间人工工序'],
            ['op_type' => 'pack', 'op_name' => '包装', 'is_molding' => 0, 'machine_type' => null, 'workshop' => 'pack', 'sort' => 5, 'is_active' => 1, 'remark' => '包装线人工工序'],
        ];

        foreach ($items as $item) {
            // updateOrCreate 同步修正存量字典中效果工序仍归喷漆车间的记录。
            OpType::updateOrCreate(['op_type' => $item['op_type']], $item);
        }
    }
}
