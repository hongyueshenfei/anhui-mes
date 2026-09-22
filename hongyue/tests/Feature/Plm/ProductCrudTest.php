<?php

namespace Tests\Feature\Plm;

use App\Models\Bom;
use App\Models\Machine;
use App\Models\PlmRoute;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 产品/BOM 冒烟测试（一期验收 A7：编码对齐金蝶、状态流转有记录；A8：版本管理、未发布不可引用）
 */
class ProductCrudTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    public function test_create_product_with_kingdee_code(): void
    {
        $response = $this->api($this->token)->postJson('/api/plm/products', [
            'code' => 'P-0101',
            'name' => '测试杯 300ml',
            'spec' => 'PP 材质',
            'unit' => '只',
            'status' => 'developing',
            'weight' => 15.5,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'P-0101');

        $this->assertDatabaseHas('products', ['code' => 'P-0101', 'status' => 'developing']);
    }

    public function test_product_status_transition_records_audit(): void
    {
        $product = Product::where('code', 'P-0004')->firstOrFail(); // developing

        // 开发 → 试产
        $this->api($this->token)->postJson("/api/plm/products/{$product->id}/status", ['status' => 'trial'])->assertOk();
        // 试产 → 量产
        $this->api($this->token)->postJson("/api/plm/products/{$product->id}/status", ['status' => 'mass'])->assertOk();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'mass']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'plm', 'action' => 'product_status', 'target_id' => $product->id]);
    }

    public function test_invalid_status_transition_rejected(): void
    {
        $product = Product::where('code', 'P-0004')->firstOrFail(); // developing

        // 开发 直接跳 量产：不合法（须经试产）
        $response = $this->api($this->token)->postJson("/api/plm/products/{$product->id}/status", ['status' => 'mass']);

        $response->assertStatus(422);
    }

    public function test_bom_create_version_and_publish(): void
    {
        $product = Product::where('code', 'P-0002')->firstOrFail();

        // 新建 V3.0 草稿
        $create = $this->api($this->token)->postJson("/api/plm/products/{$product->id}/boms", [
            'version' => 'V3.0',
            'remark' => '测试版本',
            'lines' => [
                ['item_code' => 'RAW-PET-001', 'item_name' => 'PET 原料', 'qty' => 1.05, 'unit' => 'kg', 'loss_rate' => 1.5, 'is_key' => 1],
                ['item_code' => 'PKG-BOX-002', 'item_name' => '大号彩盒', 'qty' => 1, 'unit' => '个', 'loss_rate' => 0, 'is_key' => 0],
            ],
        ]);

        $create->assertStatus(201);
        $bomId = $create->json('data.id');

        // 草稿不可通过 published 接口获取
        $this->api($this->token)->getJson("/api/plm/products/{$product->id}/boms/published")
            ->assertJsonPath('data.id', $product->getPublishedBom()->id);

        // 同产品唯一已发布版本规则：先退回旧版本，再发布新版本
        $oldPublished = $product->getPublishedBom();
        $this->api($this->token)->postJson("/api/plm/boms/{$oldPublished->id}/revoke")->assertOk();

        // 发布
        $this->api($this->token)->postJson("/api/plm/boms/{$bomId}/publish")->assertOk();

        $this->assertDatabaseHas('boms', ['id' => $bomId, 'status' => 'published']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'plm', 'action' => 'publish_bom']);

        // 发布后 published 接口返回新版本
        $this->api($this->token)->getJson("/api/plm/products/{$product->id}/boms/published")
            ->assertJsonPath('data.id', $bomId);
    }

    public function test_empty_bom_cannot_publish(): void
    {
        $product = Product::where('code', 'P-0002')->firstOrFail();

        $create = $this->api($this->token)->postJson("/api/plm/products/{$product->id}/boms", [
            'version' => 'V3.0',
            'remark' => '无明细版本',
            'lines' => [],
        ]);

        $create->assertStatus(201);
        $bomId = $create->json('data.id');

        $this->api($this->token)->postJson("/api/plm/boms/{$bomId}/publish")->assertStatus(422);
    }

    public function test_unpublished_route_cannot_be_referenced(): void
    {
        $product = Product::where('code', 'P-0005')->firstOrFail();

        // 创建草稿工艺路线（成型工序需指定车间机台，满足「按车间选机台」校验）
        $injMachine = Machine::where('code', 'I-01')->firstOrFail();
        $create = $this->api($this->token)->postJson('/api/plm/routes', [
            'name' => '未发布测试工艺',
            'version' => 'R2',
            'ops' => [
                ['op_type' => 'molding_injection', 'op_name' => '注塑成型', 'mold_change_min' => 30, 'machine_type' => 'injection', 'shift_mode' => 'two_shift', 'default_machine_id' => $injMachine->id],
            ],
        ]);

        $create->assertStatus(201);
        $routeId = $create->json('data.id');

        // 未发布版本设为默认 → 422（验收 A8：未发布不可被引用）
        $response = $this->api($this->token)->putJson("/api/plm/products/{$product->id}", [
            'code' => $product->code,
            'name' => $product->name,
            'default_route_id' => $routeId,
        ]);

        $response->assertStatus(422);
    }

    public function test_route_copy_from_similar_product(): void
    {
        // 验收 A9：复制 P-0001 的工艺路线（含 4 工序）为新名称/版本
        $sourceProduct = Product::where('code', 'P-0001')->firstOrFail();
        $sourceRoute = $sourceProduct->getPublishedRoute();
        $this->assertNotNull($sourceRoute);

        $response = $this->api($this->token)->postJson("/api/plm/routes/{$sourceRoute->id}/copy", [
            'name' => 'P-0005 复制工艺',
            'version' => 'R2',
            'remark' => '复制自 P-0001',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'P-0005 复制工艺')
            ->assertJsonCount(4, 'data.ops'); // 4 道工序完整复制

        $this->assertDatabaseHas('routes', ['name' => 'P-0005 复制工艺', 'version' => 'R2', 'status' => 'draft']);
    }

    public function test_capacity_preview(): void
    {
        // 验收 A9：1000 件 × 400 件/h + 换模 30min = 3 小时
        $response = $this->api($this->token)->getJson('/api/plm/capacity/preview?qty=1000&capacity_per_hour=400&mold_change_min=30');

        $response->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.hours', 3.15); // 含 30 分钟换模与 5% 预留
    }
}
