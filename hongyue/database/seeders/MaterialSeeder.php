<?php

namespace Database\Seeders;

use App\Models\Material;
use Illuminate\Database\Seeder;

/**
 * 物料主数据种子（与 BOM 明细 item_code 对齐，供领料清单 spec 匹配）
 *
 * 幂等：按编码 upsert，重复执行不会产生重复物料。
 */
class MaterialSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['code' => 'RAW-PP-001', 'name' => 'PP 原料 聚丙烯', 'spec' => '注塑级 PP，MI=12', 'unit' => 'kg', 'category' => '原料'],
            ['code' => 'RAW-PE-001', 'name' => 'PE 原料 聚乙烯', 'spec' => '吹塑级 PE，MI=2', 'unit' => 'kg', 'category' => '原料'],
            ['code' => 'RAW-PET-001', 'name' => 'PET 原料', 'spec' => '食品级 PET 切片', 'unit' => 'kg', 'category' => '原料'],
            ['code' => 'COL-MB-001', 'name' => '色母粒', 'spec' => '通用色母，着色 3‰', 'unit' => 'kg', 'category' => '辅料'],
            ['code' => 'AUX-PAINT-001', 'name' => '喷涂油漆', 'spec' => '塑胶件专用漆，白色', 'unit' => 'kg', 'category' => '辅料'],
            ['code' => 'PKG-BOX-001', 'name' => '中号彩盒', 'spec' => '双瓦楞，四色印刷', 'unit' => '个', 'category' => '包材'],
            ['code' => 'PKG-BAG-001', 'name' => '包装袋', 'spec' => 'PE 自封袋 25×35cm', 'unit' => '个', 'category' => '包材'],
            ['code' => 'PKG-CARTON-001', 'name' => '外箱', 'spec' => '五层瓦楞纸箱，50 只/箱', 'unit' => '个', 'category' => '包材'],
            ['code' => 'PKG-LABEL-001', 'name' => '不干胶标签', 'spec' => '铜版纸 50×30mm', 'unit' => '个', 'category' => '包材'],
            ['code' => 'PKG-TAPE-001', 'name' => '封箱胶带', 'spec' => '透明 BOPP，宽 48mm', 'unit' => '卷', 'category' => '包材'],
        ];

        foreach ($items as $item) {
            Material::upsertByCode($item);
        }
    }
}