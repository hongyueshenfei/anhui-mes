<?php

namespace Tests\Feature\Integration;

use App\Models\IntegrationLog;
use App\Models\Order;
use App\Models\ProdTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 金蝶生产订单拉取测试（四期验收 D4）
 *
 * - 增量游标（FCreatorTime）推进
 * - 幂等：按 k3_bill_no 查重，重复拉取不产生重复订单
 * - 落库：orders(src=k3, k3_bill_no) + order_lines + prod_tasks（复用 OrderService）
 * - 留痕：integration_logs（pull_prod_order）
 */
class K3ProdOrderPullTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
        Cache::forget((string) config('k3.pull_cursor_key', 'k3.pull_prod_order_cursor'));
    }

    private function mockOrders(): array
    {
        return [
            [
                'FBillNo' => 'MO-TEST-001',
                'FCreatorTime' => '2026-01-01 09:00:00',
                'FDocumentStatus' => 'A',
                'FCustName' => '测试客户一',
                'FEntity' => [
                    ['FMaterialId' => ['FNumber' => 'P-0001'], 'FQty' => 500, 'FPlanFinishDate' => '2026-03-01'],
                ],
            ],
            [
                'FBillNo' => 'MO-TEST-002',
                'FCreatorTime' => '2026-01-02 10:00:00',
                'FDocumentStatus' => 'A',
                'FCustName' => '测试客户二',
                'FEntity' => [
                    ['FMaterialId' => ['FNumber' => 'P-0002'], 'FQty' => 300, 'FPlanFinishDate' => '2026-03-05'],
                ],
            ],
        ];
    }

    public function test_pull_creates_orders_tasks_and_log(): void
    {
        config(['k3.mock' => true]);
        config(['k3.mock_orders' => $this->mockOrders()]);

        $resp = $this->api($this->token)->postJson('/api/integration/pull-prod-orders');
        $resp->assertOk()->assertJsonPath('data.pulled', 2);

        $this->assertSame(2, Order::whereIn('k3_bill_no', ['MO-TEST-001', 'MO-TEST-002'])->count(), '拉单应创建 2 张金蝶订单');
        $this->assertSame('k3', Order::where('k3_bill_no', 'MO-TEST-001')->first()->src);

        $order = Order::where('k3_bill_no', 'MO-TEST-001')->firstOrFail();
        $this->assertSame(1, $order->lines()->count(), '订单应含 1 条明细');
        $task = ProdTask::where('order_line_id', $order->lines->first()->id)->firstOrFail();
        $this->assertSame(ProdTask::STATUS_UNSCHEDULED, $task->status, '拉单生成的任务应为待排产');
        $this->assertSame('k3', $task->source);

        // 留痕
        $log = IntegrationLog::where('api_type', 'pull_prod_order')->latest('id')->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertSame('in', $log->direction);
        $this->assertSame('k3', $log->target);

        // 游标推进到最新创建时间
        $this->assertSame('2026-01-02 10:00:00', Cache::get((string) config('k3.pull_cursor_key', 'k3.pull_prod_order_cursor')));
    }

    public function test_pull_idempotent_skips_existing_bill_no(): void
    {
        config(['k3.mock' => true]);
        config(['k3.mock_orders' => $this->mockOrders()]);

        // 预先存在同一金蝶单号订单 → 拉取时应跳过
        Order::create([
            'order_no' => 'K3-MO-TEST-001',
            'src' => Order::SRC_K3,
            'k3_bill_no' => 'MO-TEST-001',
            'customer_name' => '预置客户',
            'status' => Order::STATUS_PENDING,
            'priority' => 3,
            'delivery_date' => now()->addDays(3),
        ]);

        $resp = $this->api($this->token)->postJson('/api/integration/pull-prod-orders');
        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertSame(1, $data['pulled'], '仅新增未存在单号');
        $this->assertSame(1, $data['skipped'], '已存在单号应幂等跳过');

        $this->assertSame(1, Order::where('k3_bill_no', 'MO-TEST-001')->count(), '重复单号不产生重复订单');
        $this->assertSame(1, Order::where('k3_bill_no', 'MO-TEST-002')->count());
    }

    public function test_pull_cursor_incremental_second_run_empty(): void
    {
        config(['k3.mock' => true]);
        config(['k3.mock_orders' => $this->mockOrders()]);

        $first = $this->api($this->token)->postJson('/api/integration/pull-prod-orders');
        $first->assertOk()->assertJsonPath('data.pulled', 2);

        // 第二次：游标已推进，mock 无新数据 → 不新增
        $second = $this->api($this->token)->postJson('/api/integration/pull-prod-orders');
        $second->assertOk()->assertJsonPath('data.pulled', 0);

        $this->assertSame(2, Order::whereIn('k3_bill_no', ['MO-TEST-001', 'MO-TEST-002'])->count(), '增量游标下重复拉取不产生新订单');
    }

    public function test_pull_unmatched_product_reports_error_and_continues(): void
    {
        config(['k3.mock' => true]);
        config(['k3.mock_orders' => [
            [
                'FBillNo' => 'MO-TEST-003',
                'FCreatorTime' => '2026-01-03 09:00:00',
                'FDocumentStatus' => 'A',
                'FCustName' => '正常客户',
                'FEntity' => [
                    ['FMaterialId' => ['FNumber' => 'P-0001'], 'FQty' => 100, 'FPlanFinishDate' => '2026-03-10'],
                ],
            ],
            [
                'FBillNo' => 'MO-TEST-004',
                'FCreatorTime' => '2026-01-03 10:00:00',
                'FDocumentStatus' => 'A',
                'FCustName' => '未知物料客户',
                'FEntity' => [
                    ['FMaterialId' => ['FNumber' => 'P-NOT-EXIST'], 'FQty' => 200, 'FPlanFinishDate' => '2026-03-11'],
                ],
            ],
        ]]);

        $resp = $this->api($this->token)->postJson('/api/integration/pull-prod-orders');
        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertSame(1, $data['pulled'], '正常订单应成功拉取');
        $this->assertSame(1, $data['skipped'], '未知物料订单应跳过并报错');
        $this->assertNotEmpty($data['errors'], '未知物料应给出明确错误');

        $this->assertSame(1, Order::where('k3_bill_no', 'MO-TEST-003')->count());
        $this->assertSame(0, Order::where('k3_bill_no', 'MO-TEST-004')->count(), '未知物料订单不落库');

        // 错误留痕于集成日志
        $log = IntegrationLog::where('api_type', 'pull_prod_order')->latest('id')->firstOrFail();
        $this->assertStringContainsString('MO-TEST-004', (string) $log->error_msg, '集成日志应记录失败单号');
    }
}
