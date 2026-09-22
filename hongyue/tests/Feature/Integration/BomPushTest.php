<?php

namespace Tests\Feature\Integration;

use App\Models\Bom;
use App\Models\IntegrationLog;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * BOM 发布直发金蝶测试（四期验收 D5）
 *
 * - 推送成功才置 published（k3_push_status=pushed），回执落库
 * - 推送失败保持 draft + failed + 重试队列（≤5 次）
 * - 幂等：成功记录唯一键，重推跳过
 * - 适配器契约：ENG_BOM 表单 + 成品物料 + 明细映射
 */
class BomPushTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /** 建一个草稿 BOM（P-0002 新版本；同产品唯一已发布版本规则：发布前先退回既有 published） */
    private function draftBom(): Bom
    {
        $product = Product::where('code', 'P-0002')->firstOrFail();

        $published = Bom::where('product_id', $product->id)
            ->where('status', Bom::STATUS_PUBLISHED)
            ->first();
        if ($published) {
            $this->api($this->token)->postJson("/api/plm/boms/{$published->id}/revoke")->assertOk();
        }

        $resp = $this->api($this->token)->postJson("/api/plm/products/{$product->id}/boms", [
            'version' => 'V9.0',
            'remark' => '直发测试',
            'lines' => [
                ['item_code' => 'RAW-PET-001', 'item_name' => 'PET 瓶坯', 'qty' => 1.05, 'unit' => '个', 'loss_rate' => 2.0, 'is_key' => 1],
                ['item_code' => 'PKG-BOX-002', 'item_name' => '圆瓶彩盒', 'qty' => 1, 'unit' => '个', 'loss_rate' => 0.5, 'is_key' => 0],
            ],
        ]);

        $resp->assertStatus(201);

        return Bom::findOrFail($resp->json('data.id'));
    }

    public function test_publish_mock_success_marks_published(): void
    {
        config(['k3.mock' => true]);

        $bom = $this->draftBom();

        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/publish")->assertOk();

        $bom->refresh();
        $this->assertSame('published', $bom->status, '推送成功后才置 published');
        $this->assertSame('pushed', $bom->k3_push_status);
        $this->assertNotNull($bom->published_at);

        // 回执落库（mock 回执）
        $log = IntegrationLog::where('api_type', 'push_bom')->where('biz_id', $bom->id)->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertStringContainsString('BOM-', (string) data_get($log->response_body, 'Result.Number'));
        $this->assertStringContainsString('ENG_BOM', (string) data_get($log->request_body, 'formid'));

        // 审计
        $this->assertDatabaseHas('audit_logs', ['module' => 'plm', 'action' => 'publish_bom', 'target_id' => $bom->id]);
    }

    public function test_publish_k3_failure_keeps_draft_and_retries(): void
    {
        config(['k3.mock' => false]);
        config(['k3.base_url' => 'http://k3-mock.test']);

        $bom = $this->draftBom();

        // 登录成功；金蝶 Save 持续 500 → 发布失败，BOM 保持 draft，进入重试队列（≤5 次）
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'AuthService.Login')) {
                return Http::response(['Result' => ['ResponseStatus' => ['IsSuccess' => true]]], 200, ['Set-Cookie' => 'kdservice-sessionid=abc123']);
            }

            return Http::response('boom', 500);
        });

        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/publish")->assertOk();

        $bom->refresh();
        $this->assertSame('draft', $bom->status, '推送失败不得置 published');
        $this->assertSame('failed', $bom->k3_push_status);

        $failedLogs = IntegrationLog::where('api_type', 'push_bom')->where('biz_id', $bom->id)->where('status', 'failed')->count();
        $this->assertGreaterThanOrEqual(1, $failedLogs, '失败应留痕');
        $this->assertLessThanOrEqual(5, $failedLogs, '重试不超过 5 次');
    }

    public function test_publish_failure_then_recovery_via_retry(): void
    {
        config(['k3.mock' => false]);
        config(['k3.base_url' => 'http://k3-mock.test']);

        $bom = $this->draftBom();

        $saveAttempts = 0;
        // 登录成功；Save 第一次 500 → 失败；同步队列下重试立即执行 → 第二次成功
        Http::fake(function ($request) use (&$saveAttempts) {
            if (str_contains($request->url(), 'AuthService.Login')) {
                return Http::response(['Result' => ['ResponseStatus' => ['IsSuccess' => true]]], 200, ['Set-Cookie' => 'kdservice-sessionid=abc123']);
            }
            $saveAttempts++;
            if ($saveAttempts === 1) {
                return Http::response('boom', 500);
            }

            return Http::response([
                'Result' => [
                    'ResponseStatus' => ['IsSuccess' => true, 'Errors' => []],
                    'Id' => 52001,
                    'Number' => 'BOM-P-0002-V9.0',
                ],
            ], 200);
        });

        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/publish")->assertOk();

        $bom->refresh();
        $this->assertSame('published', $bom->status, '重试成功后应 published');
        $this->assertSame('pushed', $bom->k3_push_status);
        $this->assertSame(2, $saveAttempts, '首次失败 + 重试成功');

        $this->assertDatabaseHas('integration_logs', [
            'api_type' => 'push_bom', 'biz_id' => $bom->id, 'status' => 'failed',
        ]);
        $this->assertDatabaseHas('integration_logs', [
            'api_type' => 'push_bom', 'biz_id' => $bom->id, 'status' => 'success',
        ]);
        $this->assertSame(1, IntegrationLog::where('api_type', 'push_bom')->where('biz_id', $bom->id)->where('status', 'success')->count(), '成功记录唯一（幂等）');
    }

    public function test_adapter_field_mapping_contract(): void
    {
        $bom = Bom::where('version', 'V2.0')->firstOrFail();

        $payload = (new \App\Modules\Integration\Services\K3BomPushAdapter())->adapt($bom);

        $this->assertSame('ENG_BOM', $payload['formid']);
        $this->assertSame($bom->product->code, data_get($payload, 'model.FMaterialId.FNumber'));
        $entity = data_get($payload, 'model.FEntity', []);
        $this->assertNotEmpty($entity);
        $first = $entity[0];
        $this->assertNotEmpty(data_get($first, 'FMaterialId.FNumber'));
        $this->assertGreaterThan(0, (float) data_get($first, 'FQty'));
        $this->assertStringContainsString($bom->version, (string) data_get($payload, 'model.FNumber'));
    }
}
