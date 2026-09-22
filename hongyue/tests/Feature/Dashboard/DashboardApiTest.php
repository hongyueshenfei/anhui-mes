<?php

namespace Tests\Feature\Dashboard;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProdTask;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 看板 API 冒烟测试（四期：工作台/订单总览/任务看板/产能热力/报表）
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function seedOrder(): ProdTask
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        $order = Order::create([
            'order_no' => 'SO-DASH-'.uniqid(),
            'src' => Order::SRC_MANUAL,
            'customer_name' => '看板测试客户',
            'status' => Order::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->addDays(2)->toDateString(),
        ]);
        $line = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1000,
            'delivery_date' => now()->addDays(2)->toDateString(),
            'priority' => 3,
            'status' => OrderLine::STATUS_IN_PROGRESS,
            'done_qty' => 200,
            'mold_id' => $product->default_mold_id,
            'line_no' => 1,
        ]);

        return ProdTask::create([
            'task_no' => 'TSK-DASH-'.uniqid(),
            'order_line_id' => $line->id,
            'product_id' => $product->id,
            'route_id' => $product->default_route_id,
            'mold_id' => $product->default_mold_id,
            'qty' => 1000,
            'done_qty' => 200,
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->addDays(2)->toDateString(),
            'deviation_pct' => 0,
            'plan_start' => now()->subDay(),
            'plan_end' => now()->addDays(1),
            'is_locked' => 1,
            'source' => 'manual',
        ]);
    }

    public function test_workbench_returns_kpi(): void
    {
        $this->seedOrder();

        $resp = $this->api($this->token)->getJson('/api/dashboard/workbench');
        $resp->assertOk();
        $this->assertArrayHasKey('stats', $resp->json('data'));
        $this->assertArrayHasKey('orders', $resp->json('data.stats'));
        $this->assertSame(1, $resp->json('data.stats.orders.total'));
    }

    public function test_order_overview_groups(): void
    {
        $this->seedOrder();

        $resp = $this->api($this->token)->getJson('/api/dashboard/order-overview');
        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertArrayHasKey('groups', $data);
        $this->assertArrayHasKey('pending', $data['groups']);
        $this->assertArrayHasKey('in_progress', $data['groups']);
        $this->assertArrayHasKey('overdue', $data['groups']);
        $this->assertArrayHasKey('done', $data['groups']);
        $this->assertSame(1, $data['groups']['in_progress']['count']);
        $this->assertSame(1, count($data['items']));

        // 逾期标记：交期已过
        $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '逾期客户',
            'delivery_date' => now()->subDays(3)->toDateString(),
            'lines' => [['product_code' => 'P-0002', 'qty' => 500]],
        ])->assertStatus(201);

        $resp2 = $this->api($this->token)->getJson('/api/dashboard/order-overview');
        $overdue = collect($resp2->json('data.items'))->firstWhere('due_flag', 'overdue');
        $this->assertNotNull($overdue, '交期已过订单应标记 overdue');
    }

    public function test_task_board_returns_overview(): void
    {
        $task = $this->seedOrder();

        $resp = $this->api($this->token)->getJson('/api/dashboard/task-board');
        $resp->assertOk();
        $this->assertArrayHasKey('overview', $resp->json('data'));
        $this->assertArrayHasKey('items', $resp->json('data'));
        $this->assertSame(1, $resp->json('data.overview.in_progress'));
        $item = collect($resp->json('data.items'))->firstWhere('id', $task->id);
        $this->assertSame(20, $item['progress_pct']);
    }

    public function test_load_heatmap_returns_machine_cells(): void
    {
        $resp = $this->api($this->token)->getJson('/api/dashboard/load-heatmap');
        $resp->assertOk();
        $data = $resp->json('data');
        $this->assertSame(7, count($data['days']));
        $this->assertNotEmpty($data['rows']);
        $this->assertSame(7, count($data['rows'][0]['cells']));
        $this->assertArrayHasKey('alert_threshold', $data);
    }

    public function test_reports_returns_metrics(): void
    {
        $this->seedOrder();

        $resp = $this->api($this->token)->getJson('/api/dashboard/reports');
        $resp->assertOk();
        $metrics = collect($resp->json('data.metrics'));
        $this->assertTrue($metrics->contains('key', 'delivery_rate'));
        $this->assertTrue($metrics->contains('key', 'defect_rate'));
        $this->assertTrue($metrics->contains('key', 'overdue_orders'));
        $this->assertArrayHasKey('mold_life', $resp->json('data'));
    }
}
