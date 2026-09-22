<?php

namespace Tests\Feature\Qa;

use App\Models\Bom;
use App\Models\IntegrationLog;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProdTask;
use App\Models\Product;
use App\Models\RedLightClose;
use App\Modules\Integration\Jobs\PushBomJob;
use App\Modules\Integration\Jobs\PushProgressJob;
use App\Modules\Production\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 四期收尾独立验收补充测试（QA 严过关 · fresh eyes）
 *
 * 覆盖工程师自测未覆盖的边界（对应验收任务 8）：
 * 1) 红灯：no_report_hours 可配参数反向验证；关闭后数据恶化仍被跳过
 * 2) 金蝶拉单：同一创建时间多单在第二批不遗漏；无新数据游标稳定
 * 3) BOM：重复发布幂等不重复推送；重试次数耗尽最终状态 failed 且不再重试
 * 4) 小程序回写：通用查询/双空 422；push_url 未配置仅留痕；
 *    可发货量与入库红字回冲联动（负向）；HMAC 时间窗边界（299s/301s）；契约字段一致
 */
class QaPhase4AcceptanceTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
        Cache::forget((string) config('k3.pull_cursor_key', 'k3.pull_prod_order_cursor'));
    }

    // ==================== 红灯 ====================

    public function test_redlight_no_report_hours_configurable_both_directions(): void
    {
        // 计划起点 5 小时前、无报工
        $task = $this->makeRedLightTask([
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'plan_start' => now()->subHours(5)->toDateTimeString(),
            'plan_end' => now()->addDays(2)->toDateTimeString(),
        ]);

        // 阈值 6 小时 → 不触发（未报工超时 N 可配，调大不误报）
        config(['dashboard.no_report_hours' => 6]);
        $items = collect($this->api($this->token)->getJson('/api/dashboard/red-lights?fresh=1')->json('data.items'));
        $this->assertFalse($items->contains(fn ($r) => $r['source'] === 'no_report' && $r['task_id'] === (int) $task->id), 'N=6 时 5 小时前计划任务不应触发未报工');

        // 阈值 3 小时 → 触发
        config(['dashboard.no_report_hours' => 3]);
        $items2 = collect($this->api($this->token)->getJson('/api/dashboard/red-lights?fresh=1')->json('data.items'));
        $this->assertTrue($items2->contains(fn ($r) => $r['source'] === 'no_report' && $r['task_id'] === (int) $task->id), 'N=3 时 5 小时前计划任务应触发未报工');
    }

    public function test_redlight_closed_stays_hidden_even_if_data_worsens(): void
    {
        // 构造逾期红灯并关闭
        $task = $this->makeRedLightTask([
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'delivery_date' => now()->subDays(2)->toDateString(),
        ]);

        $items = $this->api($this->token)->getJson('/api/dashboard/red-lights?fresh=1')->json('data.items');
        $light = collect($items)->first(fn ($r) => $r['source'] === 'overdue' && $r['task_id'] === (int) $task->id);
        $this->assertNotNull($light, '前置：应存在逾期红灯');

        $this->api($this->token)->postJson("/api/dashboard/red-lights/{$light['id']}/close", [
            'reason' => '客户同意延期',
        ])->assertOk();

        // 数据进一步恶化：交期再提前 3 天（逾期更严重）
        $task->update(['delivery_date' => now()->subDays(5)->toDateString()]);

        $items2 = collect($this->api($this->token)->getJson('/api/dashboard/red-lights?fresh=1')->json('data.items'));
        $this->assertFalse($items2->contains(fn ($r) => $r['source'] === 'overdue' && $r['task_id'] === (int) $task->id), '已人工关闭的红灯在数据恶化后仍不应重复打扰（red_light_closes 唯一键生效）');
        $this->assertSame(1, RedLightClose::where('source', 'overdue')->where('task_id', $task->id)->count(), '关闭记录唯一');
    }

    // ==================== 金蝶拉单 ====================

    public function test_pull_same_creator_time_orders_in_second_batch_not_lost(): void
    {
        // 第一批：两张单同一创建时间（09:00:00）
        config(['k3.mock' => true]);
        config(['k3.mock_orders' => $this->sameTimeOrders('MO-SAME-001', 'MO-SAME-002')]);

        $first = $this->api($this->token)->postJson('/api/integration/pull-prod-orders');
        $first->assertOk()->assertJsonPath('data.pulled', 2);
        $this->assertSame('2026-01-01 09:00:00', Cache::get((string) config('k3.pull_cursor_key')));

        // 第二批：同一创建时间（09:00:00）又出现一张历史补录单 → 不应遗漏
        config(['k3.mock_orders' => $this->sameTimeOrders('MO-SAME-001', 'MO-SAME-002', 'MO-SAME-003')]);
        $second = $this->api($this->token)->postJson('/api/integration/pull-prod-orders');
        $second->assertOk();

        $pulled = (int) $second->json('data.pulled');
        $this->assertSame(1, $pulled, '同一创建时间的补录单应被增量拉取（游标应按 时间+单据号 双键去重）');
        $this->assertSame(1, Order::where('k3_bill_no', 'MO-SAME-003')->count(), '同时间戳补录单不应漏拉');
    }

    public function test_pull_cursor_stays_when_no_new_data(): void
    {
        config(['k3.mock' => true]);
        config(['k3.mock_orders' => $this->sameTimeOrders('MO-SAME-001')]);

        $this->api($this->token)->postJson('/api/integration/pull-prod-orders')->assertOk();
        $this->assertSame('2026-01-01 09:00:00', Cache::get((string) config('k3.pull_cursor_key')));

        // 无新数据：游标不变，pulled=0
        $again = $this->api($this->token)->postJson('/api/integration/pull-prod-orders');
        $again->assertOk()->assertJsonPath('data.pulled', 0);
        $this->assertSame('2026-01-01 09:00:00', Cache::get((string) config('k3.pull_cursor_key')), '无新数据时游标不应回退');
    }

    public function test_k3_mock_server_login_without_cookie_breaks_real_http_pull(): void
    {
        // P1-2 回归：scripts/k3-mock-server.php Login 曾返回 {"status":"ok"} 但不带 Set-Cookie，
        // K3Client::sessionCookie() 在 k3.mock=false 时要求登录返回 Cookie 否则抛异常 → 真实 HTTP 拉单断裂。
        // 已修复：mock server Login 现在返回 Set-Cookie: kdservice-sessionid=mock-session-<随机>。
        // 期望：mock=false + 本地 mock server 沙箱联调应可端到端拉单（Cookie 被 K3Client 正确解析）。
        config(['k3.mock' => false]);
        config(['k3.base_url' => 'http://127.0.0.1:8002']);

        Http::fake([
            // 与修复后 scripts/k3-mock-server.php Login 行为一致：返回会话 Cookie
            'http://127.0.0.1:8002/*AuthService.Login' => Http::response(['status' => 'ok'], 200, ['Set-Cookie' => 'kdservice-sessionid=mock-session-e2e; Path=/']),
            'http://127.0.0.1:8002/*ExecuteBillQuery' => Http::response(['Result' => [
                [
                    'FBillNo' => 'MO-E2E-001',
                    'FCreatorTime' => '2026-01-06 08:00:00',
                    'FDocumentStatus' => 'A',
                    'FCustName' => 'E2E客户',
                    'FEntity' => [
                        ['FMaterialId' => ['FNumber' => 'P-0001'], 'FQty' => 200, 'FPlanFinishDate' => '2026-03-01'],
                    ],
                ],
            ]]),
        ]);

        $resp = $this->api($this->token)->postJson('/api/integration/pull-prod-orders');
        $resp->assertOk();

        $this->assertSame(1, $resp->json('data.pulled'), 'mock server Login 返回会话 Cookie 后应经真实 HTTP 拉取成功');
        $this->assertSame(1, Order::where('k3_bill_no', 'MO-E2E-001')->count(), '端到端拉单应落库');
    }

    // ==================== BOM 直发 ====================

    public function test_bom_publish_idempotent_no_duplicate_push(): void
    {
        config(['k3.mock' => true]);
        $bom = $this->draftBom();

        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/publish")->assertOk();
        $bom->refresh();
        $this->assertSame('published', $bom->status);

        $successBefore = IntegrationLog::where('api_type', 'push_bom')->where('biz_id', $bom->id)->where('status', 'success')->count();

        // 重复发布（已 published）→ 应直接返回，不重复推送
        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/publish")->assertOk();

        $successAfter = IntegrationLog::where('api_type', 'push_bom')->where('biz_id', $bom->id)->where('status', 'success')->count();
        $this->assertSame($successBefore, $successAfter, '重复发布不应产生重复推送');
        $this->assertSame(1, $successAfter, '成功记录唯一（幂等）');
    }

    public function test_bom_retry_exhaustion_final_state_failed_and_stops(): void
    {
        config(['k3.mock' => false]);
        config(['k3.base_url' => 'http://k3-mock.test']);

        $bom = $this->draftBom();

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'AuthService.Login')) {
                return Http::response(['Result' => ['ResponseStatus' => ['IsSuccess' => true]]], 200, ['Set-Cookie' => 'kdservice-sessionid=abc123']);
            }

            return Http::response('boom', 500);
        });

        // 模拟队列重试耗尽（sync 队列下 delay 立即执行，直接 publish 会连锁触发多次）
        $this->api($this->token)->postJson("/api/plm/boms/{$bom->id}/publish")->assertOk();

        $bom->refresh();
        $this->assertSame('draft', $bom->status, '推送始终失败不得置 published');
        $this->assertSame('failed', $bom->k3_push_status, '重试耗尽后最终状态应为 failed');

        $failedLogs = IntegrationLog::where('api_type', 'push_bom')->where('biz_id', $bom->id)->where('status', 'failed')->count();
        $this->assertSame(5, $failedLogs, '总尝试次数应恰为 5 次（首次 + 4 次重试）后停止');

        // 再次手动触发（模拟人工重推）→ 只执行本次 1 次推送，不级联自动重试（日志只 +1，不 +5）
        $before = IntegrationLog::where('api_type', 'push_bom')->where('biz_id', $bom->id)->count();
        (new PushBomJob($bom->id))->handle();
        $after = IntegrationLog::where('api_type', 'push_bom')->where('biz_id', $bom->id)->count();
        $this->assertSame($before + 1, $after, '重试耗尽后手动触发只执行 1 次，不再级联自动重试');
    }

    // ==================== 小程序回写 ====================

    private function sign(string $method, string $path, ?int $timestamp = null): array
    {
        $ts = $timestamp ?? time();
        $canonical = $ts."\n".strtoupper($method)."\n".$path;
        $sig = base64_encode(hash_hmac('sha256', $canonical, (string) config('mp.app_secret'), true));

        return [
            'X-App-Key' => (string) config('mp.app_key'),
            'X-Timestamp' => (string) $ts,
            'X-Signature' => $sig,
        ];
    }

    public function test_mp_generic_query_and_empty_params_422(): void
    {
        [$order, $task] = $this->makeProgressOrder();

        // 通用查询 ?order_no=（签名 path 不含 query string，与 $request->path() 一致）
        $resp = $this->withHeaders($this->sign('GET', 'api/mp/progress'))->getJson('/api/mp/progress?order_no='.$order->order_no);
        $resp->assertOk()->assertJsonPath('data.level', 'order');

        // 通用查询 ?product_code=
        $resp2 = $this->withHeaders($this->sign('GET', 'api/mp/progress'))->getJson('/api/mp/progress?product_code=P-0001');
        $resp2->assertOk()->assertJsonPath('data.level', 'product');

        // 都为空 → 422
        $resp3 = $this->withHeaders($this->sign('GET', 'api/mp/progress'))->getJson('/api/mp/progress');
        $resp3->assertStatus(422);
    }

    public function test_mp_push_progress_unconfigured_only_logs(): void
    {
        config(['mp.push_url' => null]);
        [$order, $task] = $this->makeProgressOrder();

        // 未配置 push_url → 仅留痕，不发起 HTTP
        Http::fake();
        (new PushProgressJob($order->id))->handle(app(\App\Modules\Integration\Services\MiniProgramClient::class), app(\App\Modules\Integration\Services\MpProgressService::class));

        Http::assertNothingSent();

        $log = IntegrationLog::where('api_type', 'push_progress')->where('biz_id', $order->id)->latest('id')->first();
        $this->assertNotNull($log, 'push_url 未配置时应留痕');
        $this->assertStringContainsString('未配置', (string) $log->error_msg);
    }

    public function test_mp_push_progress_configured_posts_with_signature(): void
    {
        config(['mp.push_url' => 'https://mp.example.com/api/progress']);
        [$order, $task] = $this->makeProgressOrder();

        Http::fake([
            'mp.example.com/*' => Http::response(['ok' => true], 200),
        ]);

        (new PushProgressJob($order->id))->handle(app(\App\Modules\Integration\Services\MiniProgramClient::class), app(\App\Modules\Integration\Services\MpProgressService::class));

        // 推送应携带 appKey + 时间戳 + HMAC 签名头
        Http::assertSent(function ($request) {
            $key = (string) ($request->header('X-App-Key')[0] ?? '');
            $ts = (int) ($request->header('X-Timestamp')[0] ?? 0);
            $sig = (string) ($request->header('X-Signature')[0] ?? '');

            if ($key !== (string) config('mp.app_key')) {
                return false;
            }
            if (abs(time() - $ts) > 300) {
                return false;
            }

            $canonical = $ts."\nPOST\n/api/progress";
            $expected = base64_encode(hash_hmac('sha256', $canonical, (string) config('mp.app_secret'), true));

            return hash_equals($expected, $sig);
        });

        $log = IntegrationLog::where('api_type', 'push_progress')->where('biz_id', $order->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('success', $log->status);
    }

    public function test_mp_shipable_qty_decreases_after_stock_reversal(): void
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        $order = Order::create([
            'order_no' => 'SO-RV-'.uniqid(),
            'src' => Order::SRC_MANUAL,
            'customer_name' => '回冲客户',
            'status' => Order::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->addDays(3)->toDateString(),
        ]);
        $line = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1000,
            'delivery_date' => now()->addDays(3)->toDateString(),
            'priority' => 3,
            'status' => OrderLine::STATUS_IN_PROGRESS,
            'done_qty' => 400,
            'mold_id' => $product->default_mold_id,
            'line_no' => 1,
        ]);
        $task = ProdTask::create([
            'task_no' => 'TSK-RV-'.uniqid(),
            'order_line_id' => $line->id,
            'product_id' => $product->id,
            'route_id' => $product->default_route_id,
            'mold_id' => $product->default_mold_id,
            'qty' => 1000,
            'done_qty' => 400,
            'stocked_qty' => 300,
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->addDays(3)->toDateString(),
            'deviation_pct' => 0,
            'plan_start' => now(),
            'plan_end' => now()->addDays(1),
            'is_locked' => 1,
            'source' => 'manual',
        ]);

        // 前置：先报工 +400（净完成 400），可发货量 = Σ已入库 = 300
        $operator = \App\Models\User::where('employee_no', 'admin')->firstOrFail();
        app(ReportService::class)->report([
            'task_id' => $task->id,
            'qty' => 400,
            'defect_qty' => 0,
            'source' => 'scan',
        ], $operator);

        $path = 'api/mp/orders/'.$order->order_no.'/progress';
        $before = $this->withHeaders($this->sign('GET', $path))->getJson('/api/mp/orders/'.$order->order_no.'/progress');
        $before->assertOk()->assertJsonPath('data.shipable_qty', 300);

        // 冲减报工 150：净完成 400-150=250 < 已入库 300 → 自动红字回冲 50 → stocked_qty=250
        $result = app(ReportService::class)->report([
            'task_id' => $task->id,
            'qty' => -150,
            'defect_qty' => 0,
            'remark' => 'QA 验收：冲减回冲联动',
            'source' => 'scan',
        ], $operator);

        $this->assertNotNull($result['stock_in_correction'], '冲减触及已入库应触发红字回冲');
        $this->assertSame(50, $result['stock_in_correction']['needed']);
        $this->assertSame(250, (int) $task->fresh()->stocked_qty);

        // 回冲后：可发货量联动减少（负向）
        $after = $this->withHeaders($this->sign('GET', $path))->getJson('/api/mp/orders/'.$order->order_no.'/progress');
        $after->assertOk()->assertJsonPath('data.shipable_qty', 250);
    }

    public function test_mp_hmac_timestamp_window_boundary(): void
    {
        // 窗口内（299s 前）→ 鉴权通过（订单不存在返回 404，而非 401）
        $path = 'api/mp/orders/SO-ANY/progress';
        $inWindow = $this->withHeaders($this->sign('GET', $path, time() - 299))->getJson('/api/mp/orders/SO-ANY/progress');
        $inWindow->assertStatus(404);

        // 窗口外（301s 前）→ 防重放拒绝 401
        $outWindow = $this->withHeaders($this->sign('GET', $path, time() - 301))->getJson('/api/mp/orders/SO-ANY/progress');
        $outWindow->assertStatus(401);

        // 未来时间戳同样拒绝（防回放）
        $future = $this->withHeaders($this->sign('GET', $path, time() + 301))->getJson('/api/mp/orders/SO-ANY/progress');
        $future->assertStatus(401);
    }

    public function test_mp_contract_fields_match_doc(): void
    {
        [$order, $task] = $this->makeProgressOrder();

        $path = 'api/mp/orders/'.$order->order_no.'/progress';
        $resp = $this->withHeaders($this->sign('GET', $path))->getJson('/api/mp/orders/'.$order->order_no.'/progress');
        $resp->assertOk();

        $data = $resp->json('data');
        // 契约 §3.1 字段清单
        foreach (['level', 'biz_no', 'order_no', 'order_id', 'qty', 'done_qty', 'progress_pct', 'expected_finish', 'delayed', 'shipable_qty', 'status', 'updated_at'] as $field) {
            $this->assertArrayHasKey($field, $data, "契约字段 {$field} 应存在");
        }
        $this->assertSame($order->order_no, $data['order_no']);
        $this->assertSame($order->id, $data['order_id']);
        $this->assertIsBool($data['delayed']);
    }

    // ==================== 工具方法 ====================

    private function makeRedLightTask(array $overrides = []): ProdTask
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        $order = Order::create([
            'order_no' => 'SO-QA-'.uniqid(),
            'src' => Order::SRC_MANUAL,
            'customer_name' => 'QA 验收客户',
            'status' => Order::STATUS_PENDING,
            'priority' => 3,
            'delivery_date' => now()->addDays(5),
        ]);
        $line = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1000,
            'delivery_date' => now()->addDays(5),
            'priority' => 3,
            'status' => OrderLine::STATUS_PENDING,
            'done_qty' => 0,
            'mold_id' => $product->default_mold_id,
            'line_no' => 1,
        ]);

        return ProdTask::create(array_merge([
            'task_no' => 'TSK-QA-'.uniqid(),
            'order_line_id' => $line->id,
            'product_id' => $product->id,
            'route_id' => $product->default_route_id,
            'mold_id' => $product->default_mold_id,
            'qty' => 1000,
            'done_qty' => 0,
            'status' => ProdTask::STATUS_UNSCHEDULED,
            'priority' => 3,
            'delivery_date' => now()->addDays(5)->toDateString(),
            'deviation_pct' => 0,
            'plan_start' => null,
            'plan_end' => null,
            'is_locked' => 0,
            'source' => 'manual',
        ], $overrides));
    }

    private function sameTimeOrders(string ...$billNos): array
    {
        $orders = [];
        foreach ($billNos as $i => $billNo) {
            $orders[] = [
                'FBillNo' => $billNo,
                'FCreatorTime' => '2026-01-01 09:00:00',
                'FDocumentStatus' => 'A',
                'FCustName' => '同时间客户'.$i,
                'FEntity' => [
                    ['FMaterialId' => ['FNumber' => 'P-0001'], 'FQty' => 100 + $i, 'FPlanFinishDate' => '2026-03-01'],
                ],
            ];
        }

        return $orders;
    }

    private function draftBom(): Bom
    {
        $product = Product::where('code', 'P-0002')->firstOrFail();

        // 同产品唯一已发布版本规则：发布前先退回既有 published
        $published = Bom::where('product_id', $product->id)
            ->where('status', Bom::STATUS_PUBLISHED)
            ->first();
        if ($published) {
            $this->api($this->token)->postJson("/api/plm/boms/{$published->id}/revoke")->assertOk();
        }

        $resp = $this->api($this->token)->postJson("/api/plm/products/{$product->id}/boms", [
            'version' => 'VQA'.uniqid(),
            'remark' => 'QA 直发测试',
            'lines' => [
                ['item_code' => 'RAW-PET-001', 'item_name' => 'PET 瓶坯', 'qty' => 1.05, 'unit' => '个', 'loss_rate' => 2.0, 'is_key' => 1],
                ['item_code' => 'PKG-BOX-002', 'item_name' => '圆瓶彩盒', 'qty' => 1, 'unit' => '个', 'loss_rate' => 0.5, 'is_key' => 0],
            ],
        ]);

        $resp->assertStatus(201);

        return Bom::findOrFail($resp->json('data.id'));
    }

    private function makeProgressOrder(): array
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        $order = Order::create([
            'order_no' => 'SO-MPQ-'.uniqid(),
            'src' => Order::SRC_MANUAL,
            'customer_name' => 'QA 小程序客户',
            'status' => Order::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->subDays(1)->toDateString(),
        ]);
        $line = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1000,
            'delivery_date' => now()->subDays(1)->toDateString(),
            'priority' => 3,
            'status' => OrderLine::STATUS_IN_PROGRESS,
            'done_qty' => 400,
            'mold_id' => $product->default_mold_id,
            'line_no' => 1,
        ]);
        $task = ProdTask::create([
            'task_no' => 'TSK-MPQ-'.uniqid(),
            'order_line_id' => $line->id,
            'product_id' => $product->id,
            'route_id' => $product->default_route_id,
            'mold_id' => $product->default_mold_id,
            'qty' => 1000,
            'done_qty' => 400,
            'stocked_qty' => 250,
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->subDays(1)->toDateString(),
            'deviation_pct' => 0,
            'plan_start' => now()->subDays(3),
            'plan_end' => now()->addDays(1),
            'is_locked' => 1,
            'source' => 'manual',
        ]);

        return [$order, $task];
    }
}
