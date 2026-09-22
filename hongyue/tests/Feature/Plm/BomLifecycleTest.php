<?php

namespace Tests\Feature\Plm;

use App\Modules\Plm\Services\BomPublishService;
use App\Models\Bom;
use App\Models\BomLine;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * BOM 版本生命周期：退回 / 编辑 / 禁用 / 删除 / 启用
 *
 * 注意：WithSeedData 会灌入 P-0001~P-0005 的种子 BOM，
 * 因此本组用例统一使用自定义产品编码（P-LIFECYCLE-*）构造数据，并按自身 bom_id 隔离断言。
 */
class BomLifecycleTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function makeProduct(string $code = 'P-LIFECYCLE-1', string $name = '测试产品'): Product
    {
        return Product::create([
            'code' => $code,
            'name' => $name,
            'spec' => '测试规格',
            'unit' => '只',
            'status' => Product::STATUS_MASS,
        ]);
    }

    private function makePublishedBom(Product $p, string $version = 'V1.0'): Bom
    {
        $bom = Bom::create([
            'product_id' => $p->id,
            'version' => $version,
            'status' => Bom::STATUS_DRAFT,
            'k3_push_status' => Bom::K3_NONE,
        ]);
        BomLine::create([
            'bom_id' => $bom->id,
            'seq' => 10,
            'item_code' => 'MAT-A',
            'item_name' => '材料A',
            'qty' => 1,
            'unit' => '个',
            'loss_rate' => 0,
            'is_key' => 1,
        ]);

        return app(BomPublishService::class)->publish($bom, 1)->fresh();
    }

    private function makeDraftBom(Product $p, string $version = 'V1.0'): Bom
    {
        return Bom::create([
            'product_id' => $p->id,
            'version' => $version,
            'status' => Bom::STATUS_DRAFT,
            'k3_push_status' => Bom::K3_NONE,
        ]);
    }

    // ==================== 退回 ====================

    public function test_publish_then_revoke_back_to_draft(): void
    {
        $p = $this->makeProduct();
        $bom = $this->makePublishedBom($p);

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/revoke");

        $r->assertOk()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('boms', ['id' => $bom->id, 'status' => 'draft']);
        $this->assertNotNull($r->json('data.revoked_at'));
        $this->assertDatabaseHas('audit_logs', ['module' => 'plm', 'action' => 'revoke_bom', 'target_id' => $bom->id]);
    }

    public function test_revoke_draft_throws_422(): void
    {
        $p = $this->makeProduct();
        $bom = $this->makeDraftBom($p);

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/revoke");

        $r->assertStatus(422);
        $this->assertDatabaseHas('boms', ['id' => $bom->id, 'status' => 'draft']);
    }

    public function test_revoke_disabled_throws_422(): void
    {
        $p = $this->makeProduct();
        $bom = $this->makePublishedBom($p);
        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/disable")->assertOk();

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/revoke");

        $r->assertStatus(422);
    }

    // ==================== 禁用 / 启用 ====================

    public function test_publish_then_disable(): void
    {
        $p = $this->makeProduct();
        $bom = $this->makePublishedBom($p);

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/disable");

        $r->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->assertNotNull($r->json('data.disabled_at'));
        $this->assertDatabaseHas('boms', ['id' => $bom->id, 'status' => 'disabled']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'plm', 'action' => 'disable_bom', 'target_id' => $bom->id]);
    }

    public function test_disable_published_then_enable_back(): void
    {
        $p = $this->makeProduct();
        $bom = $this->makePublishedBom($p);
        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/disable")->assertOk();

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/enable");

        $r->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertDatabaseHas('boms', ['id' => $bom->id, 'status' => 'published', 'disabled_at' => null]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'plm', 'action' => 'enable_bom', 'target_id' => $bom->id]);
    }

    public function test_disable_draft_throws_422(): void
    {
        $p = $this->makeProduct();
        $bom = $this->makeDraftBom($p);

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/disable");

        $r->assertStatus(422);
    }

    // ==================== 退回后可编辑 ====================

    public function test_revoke_then_update_lines_succeeds(): void
    {
        $p = $this->makeProduct();
        $bom = $this->makePublishedBom($p);

        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/revoke")->assertOk();

        $r = $this->api($this->token)->putJson("/api/plm/boms/{$bom->id}", [
            'lines' => [
                ['seq' => 10, 'item_code' => 'MAT-A', 'item_name' => '材料A（新）', 'qty' => 2, 'unit' => '个', 'loss_rate' => 0.5, 'is_key' => 1],
            ],
        ]);

        $r->assertOk();
        $this->assertDatabaseHas('bom_lines', ['bom_id' => $bom->id, 'item_name' => '材料A（新）', 'qty' => 2]);
    }

    // ==================== 状态机缝：disabled 不可编辑/不可发布 ====================

    public function test_update_disabled_bom_rejected(): void
    {
        $p = $this->makeProduct('P-LIFECYCLE-3', '禁用编辑测试');
        $bom = $this->makePublishedBom($p);
        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/disable")->assertOk();

        $r = $this->api($this->token)->putJson("/api/plm/boms/{$bom->id}", [
            'lines' => [['seq' => 10, 'item_code' => 'MAT-A', 'item_name' => '不应写入', 'qty' => 1, 'unit' => '个', 'loss_rate' => 0, 'is_key' => 1]],
        ]);

        $r->assertStatus(422);
        $this->assertDatabaseMissing('bom_lines', ['bom_id' => $bom->id, 'item_name' => '不应写入']);
    }

    public function test_publish_disabled_bom_rejected(): void
    {
        $p = $this->makeProduct('P-LIFECYCLE-4', '禁用发布测试');
        $bom = $this->makePublishedBom($p);
        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/disable")->assertOk();

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/publish");

        $r->assertStatus(422);
        $this->assertDatabaseHas('boms', ['id' => $bom->id, 'status' => 'disabled']);
    }
}
