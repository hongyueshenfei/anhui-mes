<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\ProdTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 订单中心测试（二期）
 * - 手工建单（多产品明细 → 自动生成生产任务）
 * - 编码唯一性校验（产品编码不存在即 422）
 * - 订单/明细状态聚合（排产后 → scheduled）
 * - 删除保护（已排产任务不可删）
 */
class OrderCrudTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    public function test_create_order_with_multi_product_lines(): void
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '测试客户甲',
            'delivery_date' => '2025-08-31',
            'priority' => 3,
            'lines' => [
                ['product_code' => 'P-0001', 'qty' => 1900],
                ['product_code' => 'P-0002', 'qty' => 2000, 'priority' => 4],
                ['product_code' => 'P-0001', 'qty' => 800],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.src', 'manual')
            ->assertJsonPath('data.status', 'route_confirm');

        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'customer_name' => '测试客户甲']);
        $this->assertDatabaseCount('order_lines', 3);

        // 每条明细生成一个生产任务（产品级）
        $tasks = ProdTask::whereIn('order_line_id', Order::find($orderId)->lines->pluck('id'))->get();
        $this->assertCount(3, $tasks);
        $this->assertTrue($tasks->every(fn ($t) => $t->status === ProdTask::STATUS_UNSCHEDULED));
        $this->assertTrue($tasks->every(fn ($t) => $t->is_locked === 0));
        $this->assertTrue($tasks->pluck('task_no')->duplicates()->isEmpty(), '任务号必须唯一');

        // 默认模具随产品
        $task = $tasks->firstWhere('product_id', \App\Models\Product::where('code', 'P-0001')->first()->id);
        $this->assertNotNull($task->mold_id, '产品默认模具应自动带出');
    }

    public function test_create_order_product_code_must_exist(): void
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '坏单客户',
            'lines' => [
                ['product_code' => 'NO-SUCH-CODE', 'qty' => 100],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_create_order_qty_must_be_positive(): void
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '坏单客户',
            'lines' => [
                ['product_code' => 'P-0001', 'qty' => 0],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_order_list_and_detail(): void
    {
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '查询客户',
            'lines' => [['product_code' => 'P-0003', 'qty' => 500]],
        ]);
        $orderId = $create->json('data.id');

        $list = $this->api($this->token)->getJson('/api/orders?keyword='.rawurlencode('查询客户'));
        $list->assertOk()->assertJsonPath('data.total', 1);

        $detail = $this->api($this->token)->getJson("/api/orders/{$orderId}");
        $detail->assertOk()->assertJsonCount(1, 'data.lines');
        $detail->assertJsonCount(1, 'data.lines.0.tasks');

        $lines = $this->api($this->token)->getJson("/api/orders/{$orderId}/lines");
        $lines->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_order_list_is_task_grained_with_product(): void
    {
        // 一个订单两个产品 → 工单中心按产品拆解为两行工单
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '工单粒度客户',
            'lines' => [
                ['product_code' => 'P-0001', 'qty' => 1900],
                ['product_code' => 'P-0003', 'qty' => 500],
            ],
        ]);
        $orderId = $create->json('data.id');

        $list = $this->api($this->token)->getJson('/api/orders?keyword='.rawurlencode('工单粒度客户'));
        $list->assertOk()->assertJsonPath('data.total', 2);

        $rows = collect($list->json('data.data'));
        // 每行 = 一个工单：工单号唯一、含产品信息、订单信息平铺
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->pluck('task_no')->duplicates()->isEmpty(), '工单中心工单号必须唯一');
        $this->assertTrue($rows->every(fn ($r) => str_starts_with($r['task_no'], 'TSK-')));
        $this->assertTrue($rows->every(fn ($r) => $r['order_id'] === $orderId && $r['order_no'] === $create->json('data.order_no')));
        $this->assertTrue($rows->every(fn ($r) => $r['customer_name'] === '工单粒度客户' && $r['src'] === 'manual'));
        $this->assertTrue($rows->every(fn ($r) => ! empty($r['product_name']) && ! empty($r['product_code'])));
        $this->assertTrue($rows->every(fn ($r) => $r['status'] === 'route_confirm' && $r['task_status'] === 'unscheduled'));
        $this->assertEquals(1900, $rows->firstWhere('product_code', 'P-0001')['qty']);
        $this->assertEquals(500, $rows->firstWhere('product_code', 'P-0003')['qty']);

        // keyword 支持按产品编码 / 工单号检索
        $byProduct = $this->api($this->token)->getJson('/api/orders?keyword=P-0001');
        $byProduct->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.product_code', 'P-0001');

        $taskNo = $rows->first()['task_no'];
        $byTask = $this->api($this->token)->getJson('/api/orders?keyword='.rawurlencode($taskNo));
        $byTask->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.task_no', $taskNo);

        // with_progress=1 → 每行带出所属工单自身工序进度
        $withProgress = $this->api($this->token)->getJson('/api/orders?keyword='.rawurlencode('工单粒度客户').'&with_progress=1');
        $withProgress->assertOk()->assertJsonPath('data.data.0.progress.percent', 0);
        $this->assertArrayHasKey('stages', $withProgress->json('data.data.0.progress'));
    }

    public function test_order_status_aggregates_to_scheduled_after_schedule(): void
    {
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '状态客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1900]],
        ]);
        $orderId = $create->json('data.id');
        $task = ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();

        $machine = \App\Models\Machine::where('code', 'I-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => Order::STATUS_SCHEDULED]);
        $this->assertDatabaseHas('order_lines', ['order_id' => $orderId, 'status' => 'scheduled']);
    }

    public function test_delete_order_with_scheduled_task_rejected(): void
    {
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '删除保护',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1900]],
        ]);
        $orderId = $create->json('data.id');

        // 待排产时可直接删除
        $this->api($this->token)->deleteJson("/api/orders/{$orderId}")->assertOk();

        // 重新建单并排产 → 删除被拒
        $create2 = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '删除保护2',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1900]],
        ]);
        $orderId2 = $create2->json('data.id');
        $task = ProdTask::firstWhere('order_line_id', Order::find($orderId2)->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$orderId2.'/route-confirm')->assertOk();
        $machine = \App\Models\Machine::where('code', 'I-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $this->api($this->token)->deleteJson("/api/orders/{$orderId2}")->assertStatus(409);
        $this->assertDatabaseHas('orders', ['id' => $orderId2]);
    }

    public function test_order_detail_attaches_task_execution_meta(): void
    {
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '执行元数据客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1900]],
        ]);
        $orderId = $create->json('data.id');
        $task = ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();
        $machine = \App\Models\Machine::where('code', 'I-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        // 排产后任务应有排程（工序级进度数据源）
        $firstSchedule = $task->schedules()->orderBy('seq')->first();
        $this->assertNotNull($firstSchedule, '排产后应生成首道工序排程');

        // 两笔报工：首条=实际开工，末条=实际完工；挂首道排程 → 计入工序级进度
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $firstSchedule->id,
            'qty' => 100,
            'source' => 'click',
            'report_time' => '2025-08-01 09:00:00',
        ])->assertCreated();

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $firstSchedule->id,
            'qty' => 200,
            'source' => 'click',
            'report_time' => '2025-08-01 11:30:00',
        ])->assertCreated();

        $detail = $this->api($this->token)->getJson("/api/orders/{$orderId}");
        $detail->assertOk()->assertJsonPath('data.lines.0.tasks.0.actual_start', '2025-08-01 09:00:00')
            ->assertJsonPath('data.lines.0.tasks.0.actual_end', '2025-08-01 11:30:00');

        // 工序级进度（stages）：详情任务按排程带去首道注塑成型，已报工 300
        $progress = $detail->json('data.lines.0.tasks.0.progress');
        $this->assertIsArray($progress, '详情任务应带工序进度 progress');
        $this->assertArrayHasKey('stages', $progress);
        $this->assertSame('molding_injection', $progress['stages'][0]['op_type'] ?? null, '首道工序应为注塑成型');
        $this->assertSame(1900, $progress['stages'][0]['plan_qty'] ?? 0, '注塑成型计划量 = 任务数量 1900');
        $this->assertSame(300, $progress['stages'][0]['done_qty'] ?? 0, '注塑成型完成量 = 两笔报工 100+200');
        $this->assertSame(16, $progress['stages'][0]['percent'] ?? 0, '注塑成型进度 = 300/1900 = 16%');

        // 累计工时 = 排程时长（I-01 产能>0，plan_end > plan_start 恒成立）
        $totalHours = (float) $detail->json('data.lines.0.tasks.0.total_hours');
        $this->assertGreaterThan(0, $totalHours, '累计工时应按排程计划时长聚合');

        // 未报工的任务：实际开工/完工为 null，累计工时为 null
        $create2 = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '无报工客户',
            'lines' => [['product_code' => 'P-0002', 'qty' => 2000]],
        ]);
        $orderId2 = $create2->json('data.id');
        $detail2 = $this->api($this->token)->getJson("/api/orders/{$orderId2}");
        $detail2->assertOk()
            ->assertJsonPath('data.lines.0.tasks.0.actual_start', null)
            ->assertJsonPath('data.lines.0.tasks.0.actual_end', null)
            ->assertJsonPath('data.lines.0.tasks.0.total_hours', null);
    }

    public function test_unauthorized_order_access_rejected(): void
    {
        $workerToken = $this->loginToken('W001');

        $this->api($workerToken)->postJson('/api/orders', [
            'customer_name' => '越权',
            'lines' => [['product_code' => 'P-0001', 'qty' => 100]],
        ])->assertStatus(403);

        // 业务员可读
        $salesToken = $this->loginToken('S001');
        $this->api($salesToken)->getJson('/api/orders')->assertOk();
    }
}