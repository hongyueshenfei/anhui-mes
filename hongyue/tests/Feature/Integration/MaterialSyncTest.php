<?php

namespace Tests\Feature\Integration;

use App\Models\IntegrationLog;
use App\Models\Material;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 物料主数据同步测试（四期验收 D6）
 *
 * - 拉取 → materials upsert（编码唯一）
 * - 对齐：products.code 在 materials 中存在则匹配，不存在 → 未匹配提示清单
 * - 手动触发 API + 集成日志留痕
 */
class MaterialSyncTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    public function test_sync_materials_upserts_by_code(): void
    {
        config(['k3.mock' => true]);

        $resp = $this->api($this->token)->postJson('/api/integration/sync-materials');
        $resp->assertOk();

        $this->assertGreaterThan(0, Material::count(), '同步后应存在物料记录');
        $this->assertSame(1, Material::where('code', 'RAW-PP-001')->count(), '编码唯一');

        // 幂等：再次同步不产生重复
        $this->api($this->token)->postJson('/api/integration/sync-materials')->assertOk();
        $this->assertSame(1, Material::where('code', 'RAW-PP-001')->count());

        // 留痕
        $this->assertDatabaseHas('integration_logs', ['api_type' => 'sync_material', 'status' => 'success']);
    }

    public function test_sync_aligns_products_and_reports_unmatched(): void
    {
        config(['k3.mock' => true]);

        $this->api($this->token)->postJson('/api/integration/sync-materials')->assertOk();

        // 种子产品 P-0001~P-0005 均在 mock 物料库中 → 无未匹配
        $unmatched = $this->api($this->token)->getJson('/api/integration/materials/unmatched')->json('data');
        $this->assertSame(0, $unmatched['total'], '种子产品编码应与金蝶物料对齐');

        // 新增一个未备案产品 → 进入未匹配清单
        Product::create([
            'code' => 'P-8888',
            'name' => '新开发产品（未备案）',
            'spec' => '试产',
            'unit' => '只',
            'status' => 'developing',
            'weight' => 10,
        ]);

        $unmatched2 = $this->api($this->token)->getJson('/api/integration/materials/unmatched')->json('data');
        $this->assertSame(1, $unmatched2['total']);
        $this->assertSame('P-8888', $unmatched2['items'][0]['code']);
        $this->assertStringContainsString('未找到', (string) $unmatched2['items'][0]['reason']);
    }

    public function test_sync_material_k3_failure_logged(): void
    {
        config(['k3.mock' => false]);
        config(['k3.base_url' => 'http://k3-mock.test']);
        \Illuminate\Support\Facades\Http::fake(['k3-mock.test/*' => \Illuminate\Support\Facades\Http::response('boom', 500)]);

        $this->api($this->token)->postJson('/api/integration/sync-materials')->assertOk();

        $this->assertDatabaseHas('integration_logs', ['api_type' => 'sync_material', 'status' => 'failed']);
    }
}
