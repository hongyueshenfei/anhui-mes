<?php

namespace Database\Seeders;

use App\Models\Bom;
use App\Models\BomLine;
use App\Models\Machine;
use App\Models\Mold;
use App\Models\MoldMaintainRecord;
use App\Models\PlmRoute;
use App\Models\Product;
use App\Models\RouteOp;
use App\Models\Workshop;
use Illuminate\Database\Seeder;

/**
 * 产品 / BOM / 工艺路线 / 模具 示例数据
 * （架构示例：产品 5 个、模具 5 个；P-0001 含 2 个 BOM 版本：V1.0 草稿 + V2.0 已发布）
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // ---------- 模具 5 个 ----------
        $molds = [
            ['code' => 'M-001', 'name' => '直口杯 500ml 注塑模', 'type' => Mold::TYPE_INJECTION, 'status' => Mold::STATUS_IN_USE, 'location' => 'A区-01', 'cavity_count' => 4, 'lifetime_openings' => 500000, 'opened_count' => 35600, 'purchase_cost' => 88000, 'purchase_date' => '2023-06-15', 'vendor' => '浙江精工模具', 'maintain_cycle_days' => 30, 'last_maintain_at' => now()->subDays(20), 'next_maintain_at' => now()->addDays(10), 'notes' => '直口杯专用，与 I-01~I-04 匹配'],
            ['code' => 'M-002', 'name' => '圆瓶 1L 吹塑模', 'type' => Mold::TYPE_BLOW, 'status' => Mold::STATUS_IN_USE, 'location' => 'A区-02', 'cavity_count' => 2, 'lifetime_openings' => 300000, 'opened_count' => 120000, 'purchase_cost' => 65000, 'purchase_date' => '2023-09-01', 'vendor' => '宁波吹塑模具', 'maintain_cycle_days' => 45, 'last_maintain_at' => now()->subDays(50), 'next_maintain_at' => now()->subDays(5), 'notes' => '已到期需保养（提醒示例）'],
            ['code' => 'M-003', 'name' => '方盒 800ml 注塑模', 'type' => Mold::TYPE_INJECTION, 'status' => Mold::STATUS_IDLE, 'location' => 'B区-03', 'cavity_count' => 4, 'lifetime_openings' => 400000, 'opened_count' => 8000, 'purchase_cost' => 72000, 'purchase_date' => '2024-02-20', 'vendor' => '浙江精工模具', 'maintain_cycle_days' => 30, 'last_maintain_at' => now()->subDays(10), 'next_maintain_at' => now()->addDays(20), 'notes' => null],
            ['code' => 'M-004', 'name' => '提手桶 5L 吹塑模', 'type' => Mold::TYPE_BLOW, 'status' => Mold::STATUS_REPAIR, 'location' => '维修区', 'cavity_count' => 1, 'lifetime_openings' => 200000, 'opened_count' => 198000, 'purchase_cost' => 58000, 'purchase_date' => '2022-11-10', 'vendor' => '宁波吹塑模具', 'maintain_cycle_days' => 30, 'last_maintain_at' => now()->subDays(60), 'next_maintain_at' => null, 'notes' => '接近寿命上限，维修中'],
            ['code' => 'M-005', 'name' => '喷雾瓶 300ml 注塑模', 'type' => Mold::TYPE_INJECTION, 'status' => Mold::STATUS_IN_USE, 'location' => 'A区-05', 'cavity_count' => 6, 'lifetime_openings' => 600000, 'opened_count' => 45000, 'purchase_cost' => 96000, 'purchase_date' => '2024-07-01', 'vendor' => '广东塑模科技', 'maintain_cycle_days' => 25, 'last_maintain_at' => now()->subDays(15), 'next_maintain_at' => now()->addDays(10), 'notes' => null],
        ];

        $moldModels = [];
        foreach ($molds as $item) {
            $moldModels[$item['code']] = Mold::firstOrCreate(['code' => $item['code']], $item);
        }

        // 模具-机台 适用关联
        $machineByCode = Machine::pluck('id', 'code')->all();
        $moldModels['M-001']->machines()->sync([
            $machineByCode['I-01'], $machineByCode['I-02'], $machineByCode['I-03'], $machineByCode['I-04'],
        ]);
        $moldModels['M-002']->machines()->sync([$machineByCode['B-01'], $machineByCode['B-02']]);
        $moldModels['M-003']->machines()->sync([$machineByCode['I-01'], $machineByCode['I-02']]);
        $moldModels['M-004']->machines()->sync([$machineByCode['B-01']]);
        $moldModels['M-005']->machines()->sync([$machineByCode['I-01'], $machineByCode['I-02'], $machineByCode['I-03']]);

        // 保养记录示例（M-002 已逾期）
        MoldMaintainRecord::firstOrCreate(
            ['mold_id' => $moldModels['M-002']->id, 'at' => now()->subDays(50)],
            ['type' => MoldMaintainRecord::TYPE_MAINTAIN, 'cost' => 1200, 'vendor' => '宁波吹塑模具', 'remark' => '例行保养']
        );

        // ---------- 产品 5 个 ----------
        $products = [
            ['code' => 'P-0001', 'name' => '直口杯 500ml', 'spec' => 'PP 材质 · 口径 8cm', 'unit' => '只', 'status' => Product::STATUS_MASS, 'weight' => 22.0, 'required_tonnage' => 160, 'mold' => 'M-001'],
            ['code' => 'P-0002', 'name' => '圆瓶 1L', 'spec' => 'PET 材质 · 扁圆瓶', 'unit' => '只', 'status' => Product::STATUS_MASS, 'weight' => 38.5, 'required_tonnage' => 80, 'mold' => 'M-002'],
            ['code' => 'P-0003', 'name' => '方盒 800ml', 'spec' => 'PP 材质 · 带盖', 'unit' => '只', 'status' => Product::STATUS_TRIAL, 'weight' => 45.0, 'required_tonnage' => 150, 'mold' => 'M-003'],
            ['code' => 'P-0004', 'name' => '提手桶 5L', 'spec' => 'PE 材质 · 带提手', 'unit' => '只', 'status' => Product::STATUS_DEVELOPING, 'weight' => 120.0, 'required_tonnage' => 100, 'mold' => 'M-004'],
            ['code' => 'P-0005', 'name' => '喷雾瓶 300ml', 'spec' => 'PP 材质 · 泵头款', 'unit' => '只', 'status' => Product::STATUS_DEVELOPING, 'weight' => 18.0, 'required_tonnage' => 120, 'mold' => 'M-005'],
        ];

        $paintLine = Machine::where('type', Machine::TYPE_PAINT_LINE)->where('is_active', 1)->first()?->id;
        $effectLine = Machine::where('type', Machine::TYPE_EFFECT_LINE)->where('is_active', 1)->first()?->id;
        $packLine = Machine::where('type', Machine::TYPE_PACK_LINE)->where('is_active', 1)->first()?->id;
        // 生产车间（工序 workshop_id，按字典 code 解析，避免硬编码 id）
        $workshopByCode = Workshop::pluck('id', 'code')->all();
        $injWorkshop = $workshopByCode['injection'] ?? null;
        $blowWorkshop = $workshopByCode['blow'] ?? null;
        $paintWorkshop = $workshopByCode['paint'] ?? null;
        $effectWorkshop = $workshopByCode['effect'] ?? null;
        $packWorkshop = $workshopByCode['pack'] ?? null;

        foreach ($products as $index => $item) {
            $product = Product::firstOrCreate(['code' => $item['code']], collect($item)->except('mold')->all());

            // 模具-产品 关联（is_default=1）
            $mold = $moldModels[$item['mold']];
            $mold->products()->syncWithoutDetaching([$product->id => ['is_default' => 1]]);
            $product->update(['default_mold_id' => $mold->id]);

            // ---------- BOM ----------
            $isFirst = $index === 0;
            $this->createBom($product, 'V2.0', Bom::STATUS_PUBLISHED);
            if ($isFirst) {
                $this->createBom($product, 'V1.0', Bom::STATUS_DRAFT);
            }

            // ---------- 工艺路线（独立路线库：名称+版本，产品通过 default_route_id 引用） ----------
            $routeName = $item['name'].'标准工艺';
            $route = PlmRoute::firstOrCreate(
                ['name' => $routeName, 'version' => 'R1'],
                ['status' => PlmRoute::STATUS_PUBLISHED, 'remark' => '初始工艺（种子数据）']
            );

            $moldingOp = $mold->type === Mold::TYPE_INJECTION ? RouteOp::OP_MOLDING_INJECTION : RouteOp::OP_MOLDING_BLOW;
            $machineType = $mold->type === Mold::TYPE_INJECTION ? Machine::TYPE_INJECTION : Machine::TYPE_BLOW;

            $ops = [
                // 工序序号为路线内连续序号 1/2/3/4；生产车间显式指定（workshop_id）
                ['seq' => 1, 'op_type' => $moldingOp, 'op_name' => $moldingOp === RouteOp::OP_MOLDING_INJECTION ? '注塑成型' : '吹塑成型', 'workshop_id' => $moldingOp === RouteOp::OP_MOLDING_INJECTION ? $injWorkshop : $blowWorkshop, 'mold_change_min' => 30, 'shift_mode' => 'two_shift', 'machine_type' => $machineType, 'rate_key' => 'molding'],
                ['seq' => 2, 'op_type' => RouteOp::OP_PAINT, 'op_name' => '喷漆', 'workshop_id' => $paintWorkshop, 'mold_change_min' => null, 'shift_mode' => 'day', 'machine_type' => null, 'default_line_id' => $paintLine, 'rate_key' => 'paint'],
                ['seq' => 3, 'op_type' => RouteOp::OP_EFFECT, 'op_name' => '效果处理', 'workshop_id' => $effectWorkshop, 'mold_change_min' => null, 'shift_mode' => 'day', 'machine_type' => null, 'default_line_id' => $effectLine, 'rate_key' => 'effect'],
                ['seq' => 4, 'op_type' => RouteOp::OP_PACK, 'op_name' => '包装', 'workshop_id' => $packWorkshop, 'mold_change_min' => null, 'shift_mode' => 'day', 'machine_type' => null, 'default_line_id' => $packLine, 'rate_key' => 'pack'],
            ];

            foreach ($ops as $op) {
                // updateOrCreate 同步修正存量路线中的效果工序车间/产线绑定。
                RouteOp::updateOrCreate(
                    ['route_id' => $route->id, 'seq' => $op['seq']],
                    collect($op)->except('seq')->all() + ['route_id' => $route->id]
                );
            }

            $product->update(['default_route_id' => $route->id]);
        }
    }

    /**
     * 创建 BOM；已发布 BOM 自动补齐明细（幂等，供领料清单演示）
     */
    private function createBom(Product $product, string $version, string $status): void
    {
        $bom = Bom::firstOrCreate(
            ['product_id' => $product->id, 'version' => $version],
            [
                'status' => $status,
                'k3_push_status' => Bom::K3_NONE,
                'published_at' => $status === Bom::STATUS_PUBLISHED ? now() : null,
                'published_by' => $status === Bom::STATUS_PUBLISHED
                    ? \App\Models\User::where('employee_no', 'E001')->value('id')
                    : null,
                'remark' => $status === Bom::STATUS_PUBLISHED ? '发布（种子数据）' : '草稿（种子数据）',
            ]
        );

        if ($status === Bom::STATUS_PUBLISHED) {
            $this->ensureBomLines($bom, $product);
        }
    }

    /**
     * 补齐已发布 BOM 明细到 5 行（原料/色母/包装/外箱/标签）——逐行幂等
     *
     * 已有明细行（按 item_code 匹配）跳过，不重复插入；
     * 原料单件用量按产品克重换算（weight g → kg/件），包装按规格选彩盒或包装袋。
     */
    private function ensureBomLines(Bom $bom, Product $product): void
    {
        $existing = $bom->lines()->pluck('item_code')->all();

        [$rawCode, $rawName] = match ($product->code) {
            'P-0002' => ['RAW-PET-001', 'PET 原料'],
            'P-0004' => ['RAW-PE-001', 'PE 原料 聚乙烯'],
            default => ['RAW-PP-001', 'PP 原料 聚丙烯'],
        };
        $pkg = $product->code === 'P-0004'
            ? ['PKG-BAG-001', '包装袋']
            : ['PKG-BOX-001', '中号彩盒'];

        $lines = [
            ['item_code' => $rawCode, 'item_name' => $rawName, 'qty' => round((float) $product->weight / 1000, 4), 'unit' => 'kg', 'loss_rate' => 2.0, 'is_key' => 1],
            ['item_code' => 'COL-MB-001', 'item_name' => '色母粒', 'qty' => 0.0005, 'unit' => 'kg', 'loss_rate' => 1.0, 'is_key' => 0],
            ['item_code' => $pkg[0], 'item_name' => $pkg[1], 'qty' => 1.0, 'unit' => '个', 'loss_rate' => 0.5, 'is_key' => 0],
            ['item_code' => 'PKG-CARTON-001', 'item_name' => '外箱', 'qty' => 0.02, 'unit' => '个', 'loss_rate' => 1.0, 'is_key' => 0],
            ['item_code' => 'PKG-LABEL-001', 'item_name' => '不干胶标签', 'qty' => 1.0, 'unit' => '个', 'loss_rate' => 0.5, 'is_key' => 0],
        ];

        $maxSeq = (int) $bom->lines()->max('seq');
        foreach ($lines as $line) {
            if (in_array($line['item_code'], $existing, true)) {
                continue;
            }
            $maxSeq += 1;
            BomLine::create($line + ['bom_id' => $bom->id, 'seq' => $maxSeq]);
        }
    }
}
