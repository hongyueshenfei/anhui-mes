<?php

namespace Tests\Feature\Plm;

use App\Models\Bom;
use App\Models\BomLine;
use App\Models\IntegrationLog;
use App\Models\Product;
use App\Modules\Plm\Services\BomPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * BOM 退回 → 编辑 → 重新发布闭环（QA_REJECT 回归）
 *
 * 修复点：退回时必须重置 k3_push_status=none 并清除历史成功推送日志，
 * 否则 PushBomJob 顶部幂等拦截（k3_push_status=pushed / hasSuccess）会让重新发布静默跳过。
 */
class BomRevokeRepublishTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function makeProduct(string $code = 'P-REPUB-1', string $name = '重新发布测试'): Product
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

    public function test_revoke_then_republish_succeeds(): void
    {
        $p = $this->makeProduct();
        $bom = $this->makePublishedBom($p);

        // 前置：发布成功，幂等日志存在
        $this->assertSame('published', $bom->status);
        $this->assertSame('pushed', $bom->k3_push_status);
        $this->assertTrue(IntegrationLog::hasSuccess('push_bom', 'bom', (int) $bom->id), '发布后应有成功推送记录');

        // 1. 退回：重置 k3_push_status + 清历史成功日志
        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/revoke")->assertOk();
        $this->assertDatabaseHas('boms', ['id' => $bom->id, 'status' => 'draft', 'k3_push_status' => 'none']);
        $this->assertFalse(IntegrationLog::hasSuccess('push_bom', 'bom', (int) $bom->id), '退回后成功推送记录应被清除');

        // 2. 编辑（修改明细）
        $this->api($this->token)->putJson("/api/plm/boms/{$bom->id}", [
            'lines' => [['seq' => 10, 'item_code' => 'MAT-A', 'item_name' => '材料A-修正后', 'qty' => 2, 'unit' => '个', 'loss_rate' => 0, 'is_key' => 1]],
        ])->assertOk();

        // 3. 重新发布（幂等拦截已解除，必须真正再次推送并成功）
        $r = $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/publish");
        $r->assertOk()->assertJsonPath('data.status', 'published');
        $r->assertJsonPath('data.k3_push_status', 'pushed');

        // 4. 数据库最终态：published, pushed, published_at 非空
        $bom->refresh();
        $this->assertSame('published', $bom->status);
        $this->assertSame('pushed', $bom->k3_push_status);
        $this->assertNotNull($bom->published_at);
        $this->assertDatabaseHas('bom_lines', ['bom_id' => $bom->id, 'item_name' => '材料A-修正后']);

        // 5. 重新发布后幂等日志重新落库
        $this->assertTrue(IntegrationLog::hasSuccess('push_bom', 'bom', (int) $bom->id), '重新发布后应有新的成功推送记录');
    }
}
