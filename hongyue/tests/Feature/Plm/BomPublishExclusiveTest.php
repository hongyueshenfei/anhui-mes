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
 * BOM 发布同产品唯一版本互斥（拒绝策略）
 *
 * 业务规则：一个产品只能同时只有一个已发布版本。
 * 已发布 / 已禁用（曾发布占位）均占用名额，发布冲突时返回 422，需手动退回后再发布。
 */
class BomPublishExclusiveTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function makeProduct(string $code = 'P-EXCL-1', string $name = '互斥测试产品'): Product
    {
        return Product::create([
            'code' => $code,
            'name' => $name,
            'spec' => '测试规格',
            'unit' => '只',
            'status' => Product::STATUS_MASS,
        ]);
    }

    private function makeDraftBom(Product $p, string $version = 'V1.0'): Bom
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

        return $bom;
    }

    private function makePublishedBom(Product $p, string $version = 'V1.0'): Bom
    {
        $bom = $this->makeDraftBom($p, $version);
        return app(BomPublishService::class)->publish($bom, 1)->fresh();
    }

    public function test_publish_rejected_when_same_product_other_published(): void
    {
        $p = $this->makeProduct('P-EXCL-1');
        $v1 = $this->makePublishedBom($p, 'V1.0');
        $v2 = $this->makeDraftBom($p, 'V2.0');

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$v2->id}/publish");

        $r->assertStatus(422);
        $this->assertStringContainsString('V1.0', $r->json('message'));
        $this->assertDatabaseHas('boms', ['id' => $v2->id, 'status' => 'draft']);
        $this->assertDatabaseHas('boms', ['id' => $v1->id, 'status' => 'published']);
    }

    public function test_publish_rejected_when_other_disabled(): void
    {
        $p = $this->makeProduct('P-EXCL-2');
        $v1 = $this->makePublishedBom($p, 'V1.0');
        $this->api($this->token)->postJson("/api/plm/boms/{$v1->id}/disable")->assertOk();
        $this->assertDatabaseHas('boms', ['id' => $v1->id, 'status' => 'disabled']);

        $v2 = $this->makeDraftBom($p, 'V2.0');

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$v2->id}/publish");

        $r->assertStatus(422);
        $this->assertStringContainsString('请先退回', $r->json('message'));
        $this->assertDatabaseHas('boms', ['id' => $v2->id, 'status' => 'draft']);
    }

    public function test_publish_success_when_no_conflict(): void
    {
        $p = $this->makeProduct('P-EXCL-3');
        $bom = $this->makeDraftBom($p, 'V1.0');

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/publish");

        $r->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertDatabaseHas('boms', ['id' => $bom->id, 'status' => 'published']);
    }

    public function test_publish_own_published_is_idempotent(): void
    {
        $p = $this->makeProduct('P-EXCL-4');
        $bom = $this->makePublishedBom($p, 'V1.0');
        $this->assertSame('published', $bom->status);

        $r = $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/publish");

        $r->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertDatabaseHas('boms', ['id' => $bom->id, 'status' => 'published']);
    }
}
