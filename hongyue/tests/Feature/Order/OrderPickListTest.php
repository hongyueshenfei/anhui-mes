<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 领料清单（GET /orders/{id}/pick-list）
 *
 * 规则（已确认产品决策）：
 * - 数据源 = 每个订单明细行产品的已发布 BOM（version 最大一条），不落库
 * - required_qty = round(unit_qty × 行数量 × (1 + loss_rate/100), 4)
 * - issued_qty 恒为 0；batch_no / available_qty 恒为 null（前端显示 -）
 * - spec 从 materials 表按 item_code 匹配（取不到为 null）
 * - 只读接口：调用后数据库无新增/变更
 */
class OrderPickListTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function createOrder(string $code = 'P-0001', int $qty = 5000): Order
    {
        $r = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '领料清单测试客户',
            'lines' => [['product_code' => $code, 'qty' => $qty]],
        ])->assertStatus(201);

        return Order::find($r->json('data.id'));
    }

    public function test_required_qty_calculated_from_published_bom(): void
    {
        $order = $this->createOrder('P-0001', 5000);

        $data = $this->api($this->token)->getJson("/api/orders/{$order->id}/pick-list")->assertOk()->json('data');

        $this->assertSame($order->order_no, $data['order_no']);
        $this->assertSame(1, count($data['lines']));

        $line = $data['lines'][0];
        $this->assertSame('P-0001', $line['product_code']);
        $this->assertSame(5000, $line['qty']);
        $this->assertNotNull($line['bom_id']);
        $this->assertSame('V2.0', $line['bom_version']);
        $this->assertSame('published', $line['bom_status']);

        $items = collect($line['items']);
        $this->assertSame(5, $items->count(), '已发布 BOM 应补齐 5 行明细');
        $this->assertSame(5, $data['summary']['item_count']);

        // 期望值独立手工计算（不引用接口返回值自证）
        $pp = $items->firstWhere('item_code', 'RAW-PP-001');
        $this->assertSame('注塑级 PP，MI=12', $pp['spec'], 'spec 应从 materials 按 item_code 匹配');
        $this->assertSame('kg', $pp['unit']);
        $this->assertSame(0.022, $pp['unit_qty'], '原料单件用量 = 产品克重 22g ÷ 1000');
        $this->assertEquals(2.0, (float) $pp['loss_rate']);
        $this->assertSame(112.2, $pp['required_qty'], '0.022 × 5000 × 1.02');
        $this->assertSame(0, $pp['issued_qty'], '已领数量恒为 0');
        $this->assertSame(112.2, $pp['remain_qty']);
        $this->assertNull($pp['batch_no'], '仓库批次恒为 null');
        $this->assertNull($pp['available_qty'], '库存可用量恒为 null');
        $this->assertSame(1, $pp['is_key']);

        $box = $items->firstWhere('item_code', 'PKG-BOX-001');
        $this->assertEqualsWithDelta(5025.0, $box['required_qty'], 0.0001, '1.0 × 5000 × 1.005');
        $this->assertSame(0, $box['is_key']);

        $mb = $items->firstWhere('item_code', 'COL-MB-001');
        $this->assertEqualsWithDelta(2.525, $mb['required_qty'], 0.0001, '0.0005 × 5000 × 1.01');

        $carton = $items->firstWhere('item_code', 'PKG-CARTON-001');
        $this->assertEqualsWithDelta(101.0, $carton['required_qty'], 0.0001, '0.02 × 5000 × 1.01');

        $label = $items->firstWhere('item_code', 'PKG-LABEL-001');
        $this->assertEqualsWithDelta(5025.0, $label['required_qty'], 0.0001, '1.0 × 5000 × 1.005');
    }

    public function test_published_bom_spec_missing_falls_back_null(): void
    {
        // P-0002 的 PET 原料在 materials 有 spec；删掉其 spec 模拟取不到 → null
        $order = $this->createOrder('P-0002', 2000);

        \App\Models\Material::where('code', 'RAW-PET-001')->update(['spec' => null]);

        $data = $this->api($this->token)->getJson("/api/orders/{$order->id}/pick-list")->assertOk()->json('data');

        $items = collect($data['lines'][0]['items']);
        $pet = $items->firstWhere('item_code', 'RAW-PET-001');
        $this->assertNotNull($pet);
        $this->assertNull($pet['spec'], 'materials 无 spec 时应为 null');
        $this->assertEqualsWithDelta(0.0385 * 2000 * 1.02, $pet['required_qty'], 0.0001, 'PET 0.0385kg/件 × 2000 × 1.02');
    }

    public function test_line_without_published_bom_returns_empty_items(): void
    {
        Product::create(['code' => 'P-NOBOM', 'name' => '无BOM产品', 'unit' => '只', 'status' => 'developing']);
        $order = $this->createOrder('P-NOBOM', 100);

        $data = $this->api($this->token)->getJson("/api/orders/{$order->id}/pick-list")->assertOk()->json('data');

        $line = $data['lines'][0];
        $this->assertSame([], $line['items']);
        $this->assertNull($line['bom_id']);
        $this->assertNull($line['bom_version']);
        $this->assertNull($line['bom_status']);
        $this->assertSame(0, $data['summary']['item_count']);
    }

    public function test_pick_list_is_readonly(): void
    {
        $order = $this->createOrder('P-0002', 2000);

        $tables = ['orders', 'order_lines', 'prod_tasks', 'boms', 'bom_lines', 'materials', 'work_order_logs'];
        $counts = [];
        foreach ($tables as $t) {
            $counts[$t] = DB::table($t)->count();
        }

        $this->api($this->token)->getJson("/api/orders/{$order->id}/pick-list")->assertOk();

        foreach ($tables as $t) {
            $this->assertSame($counts[$t], DB::table($t)->count(), "pick-list 不应写入 {$t}");
        }
    }
}