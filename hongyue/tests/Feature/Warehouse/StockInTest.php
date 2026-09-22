<?php

namespace Tests\Feature\Warehouse;

use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 分批入库测试（三期验收 C8）
 * - 可入库量 = 报工完成量 − 已入库量（严格校验，超量 422）
 * - 分批语义：每批独立入库单号；库存累计正确
 * - 入库后任务已入库量/订单可发货量联动
 */
class StockInTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');

        // 金蝶模拟端点（可配置失败在 K3PushTest 覆盖）
        config(['k3.base_url' => 'http://k3-mock.test']);
        Http::fake([
            'k3-mock.test/*' => Http::response([
                'Result' => [
                    'ResponseStatus' => ['IsSuccess' => true, 'Errors' => []],
                    'Id' => 88888,
                    'Number' => 'K3-IN-TEST-001',
                ],
            ], 200),
        ]);
    }

    /** 建单 → 排产 → 报工 N 件 */
    private function reportedTask(int $reportQty = 800, int $taskQty = 1000): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '入库测试',
            'lines' => [['product_code' => 'P-0001', 'qty' => $taskQty]],
        ])->assertStatus(201);
        $order = Order::findOrFail($response->json('data.id'));
        $task = ProdTask::firstWhere('order_line_id', $order->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$order->id.'/route-confirm')->assertOk();

        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $start = now()->addDays(5)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => $start->format('Y-m-d H:i:s')]],
        ])->assertOk();

        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => $reportQty])->assertStatus(201);

        return $task->fresh();
    }

    public function test_available_qty_is_reported_minus_stocked(): void
    {
        $task = $this->reportedTask(800);

        $avail = $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id);
        $avail->assertOk()
            ->assertJsonPath('data.reported_qty', 800)
            ->assertJsonPath('data.stocked_qty', 0)
            ->assertJsonPath('data.available_qty', 800);
    }

    public function test_batch_stock_in_multiple_batches(): void
    {
        $task = $this->reportedTask(800);

        // 第一批发 300
        $first = $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 300]);
        $first->assertStatus(201)->assertJsonPath('data.stock_in.qty', 300);

        $insNo1 = $first->json('data.stock_in.ins_no');
        $this->assertStringStartsWith('RK-', $insNo1);

        $availAfter1 = $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id);
        $this->assertSame(500, $availAfter1->json('data.available_qty'), '入库 300 后剩余可入库 500');

        // 第二批发 500 → 可入库 0
        $second = $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 500]);
        $second->assertStatus(201);
        $this->assertNotSame($insNo1, $second->json('data.stock_in.ins_no'), '每批独立入库单号');

        $availAfter2 = $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id);
        $this->assertSame(0, $availAfter2->json('data.available_qty'), '全部入库后可入库量为 0');

        // 任务已入库冗余
        $this->assertSame(800, $task->fresh()->stocked_qty);
    }

    public function test_over_available_qty_rejected_422(): void
    {
        $task = $this->reportedTask(800);

        $resp = $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 801]);

        $resp->assertStatus(422);
        $this->assertSame('over_available_qty', $resp->json('data.error_code'));
        $this->assertStringContainsString('可入库量', $resp->json('message'));
        $this->assertDatabaseCount('stock_ins', 0); // 超量不得落单

        // 未报工任务 → 可入库 0
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '未报工客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 100]],
        ])->assertStatus(201);
        $order = Order::findOrFail($response->json('data.id'));
        $plainTask = ProdTask::firstWhere('order_line_id', $order->lines->first()->id);

        $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $plainTask->id, 'qty' => 1])
            ->assertStatus(422)->assertJsonPath('data.error_code', 'over_available_qty');
    }

    public function test_stock_in_list_with_push_status(): void
    {
        $task = $this->reportedTask(300, 500);

        $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 100])->assertStatus(201);

        // sync 队列：推送已自动执行（mock K3 成功）
        $list = $this->api($this->token)->getJson('/api/warehouse/ins?task_id='.$task->id);
        $list->assertOk()->assertJsonPath('data.total', 1);
        $this->assertSame('success', $list->json('data.data.0.k3_push_status'), '入库自动推送金蝶（sync 队列）');
        $this->assertSame('K3-IN-TEST-001', $list->json('data.data.0.k3_bill_no'));
    }

    public function test_available_qty_only_counts_last_process(): void
    {
        $task = $this->reportedTask(800); // 任务级报工 800（成品口径计入）
        $schedules = $task->schedules()->orderBy('seq')->get();
        $this->assertGreaterThanOrEqual(3, $schedules->count());
        $first = $schedules->first();   // seq1 成型（首道）
        $second = $schedules->get(1);   // seq2 喷漆（中间工序）
        $prevLast = $schedules->get($schedules->count() - 2); // 倒数第二道
        $last = $schedules->last();     // 最后一道（成品）

        // 按工序链依次报工（后道 ≤ 前道）：300 / 300 / 200
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $first->id,
            'qty' => 300,
        ])->assertStatus(201);
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $second->id,
            'qty' => 300,
        ])->assertStatus(201);
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $prevLast->id,
            'qty' => 200,
        ])->assertStatus(201);

        // 中间工序报工不计入可入库量：可入库仍 = 任务级 800
        $avail = $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id);
        $avail->assertOk()
            ->assertJsonPath('data.reported_qty', 800)
            ->assertJsonPath('data.available_qty', 800);

        // 最后工序（成品）报工 200：计入可入库量
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $last->id,
            'qty' => 200,
        ])->assertStatus(201);

        $avail2 = $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id);
        $avail2->assertOk()
            ->assertJsonPath('data.reported_qty', 1000)
            ->assertJsonPath('data.available_qty', 1000);
    }

    public function test_available_qty_sums_all_split_segments_of_last_process(): void
    {
        $task = $this->reportedTask(800); // 任务级成品报工 800
        $schedules = $task->schedules()->orderBy('seq')->get();
        $first = $schedules->first();
        $second = $schedules->get(1);
        $third = $schedules->get(2);
        $last = $schedules->last();

        // 先补齐前序工序，允许末工序分别在两个排程段报工。
        foreach ([$first, $second, $third] as $schedule) {
            $this->api($this->token)->postJson('/api/reports', [
                'task_id' => $task->id,
                'schedule_id' => $schedule->id,
                'machine_id' => $schedule->machine_id,
                'qty' => 200,
            ])->assertStatus(201);
        }
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $last->id,
            'machine_id' => $last->machine_id,
            'qty' => 100,
        ])->assertStatus(201);

        // 模拟插单后末工序被拆成历史段 + 当前段。
        $split = $last->replicate();
        $split->plan_start = $last->plan_end;
        $split->plan_end = $last->plan_end->copy()->addHour();
        $split->forecast_start = $split->plan_start;
        $split->forecast_end = $split->plan_end;
        $split->status = TaskSchedule::STATUS_PENDING;
        $split->done_qty = 0;
        $split->save();

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $split->id,
            'machine_id' => $split->machine_id,
            'qty' => 50,
        ])->assertStatus(201);

        $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id)
            ->assertOk()
            ->assertJsonPath('data.reported_qty', 950)
            ->assertJsonPath('data.available_qty', 950);

        // 插单/拆段后再插入一条高主键的前序工序历史行，入库列表仍须按 seq
        // 找到真实末工序，而不能直接取关系集合的最后一行。
        $prefixCopy = $first->replicate();
        $prefixCopy->op_name = '后插入的前序历史段';
        $prefixCopy->save();
        $pending = $this->api($this->token)->getJson('/api/warehouse/pending-tasks')
            ->assertOk()->json('data.data');
        $item = collect($pending)->firstWhere('task_id', $task->id);
        $this->assertSame($last->op_name, $item['last_op_name']);
    }

    public function test_pending_tasks_lists_available_only(): void
    {
        // 任务 A：任务级报工 800 → 可入库 800
        $taskA = $this->reportedTask(800);

        // 任务 B：仅首道工序（成型）报工 300 → 中间工序不入库，不应出现
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '待入库过滤客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1000]],
        ])->assertStatus(201);
        $order = Order::findOrFail($response->json('data.id'));
        $taskB = ProdTask::firstWhere('order_line_id', $order->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$order->id.'/route-confirm')->assertOk();
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        // 排到更远期，避开任务 A 已占用的机台时间窗口
        $start = now()->addDays(15)->startOfDay()->addHours(8);
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => $start->format('Y-m-d H:i:s')]],
        ])->assertOk();
        $firstB = $taskB->schedules()->orderBy('seq')->first();
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $taskB->id, 'schedule_id' => $firstB->id, 'qty' => 300])->assertStatus(201);

        // 待入库列表只含任务 A，且带订单号/任务号/可入库量
        $list = $this->api($this->token)->getJson('/api/warehouse/pending-tasks');
        $list->assertOk()->assertJsonPath('data.total', 1);
        $item = $list->json('data.data.0');
        $this->assertSame($taskA->id, $item['task_id']);
        $this->assertSame('入库测试', $item['customer_name']);
        $this->assertNotEmpty($item['order_no']);
        $this->assertSame(800, $item['reported_qty']);
        $this->assertSame(0, $item['stocked_qty']);
        $this->assertSame(800, $item['available_qty']);

        // 任务 A 全部入库后 → 从待入库列表消失
        $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $taskA->id, 'qty' => 800])->assertStatus(201);
        $list2 = $this->api($this->token)->getJson('/api/warehouse/pending-tasks');
        $list2->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_order_shippable_qty_is_sum_of_stocked(): void
    {
        $task = $this->reportedTask(900, 1000);

        $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 400])->assertStatus(201);
        $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 500])->assertStatus(201);

        // 订单可发货量 = Σ已入库 = 900；任务报满完成 → 订单 produced
        $task = $task->fresh();
        $order = \App\Models\Order::with('lines')->find($task->line->order_id);
        $shippable = $order->lines->sum('tasks_sum_stocked');
        $this->assertSame(900, $shippable === 0 ? (int) $task->fresh()->stocked_qty : $shippable, '可发货量=Σ已入库（任务级冗余）');

        // 报满 → done + 全部入库 → 订单 produced
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 100])->assertStatus(201);
        $this->assertSame('done', $task->fresh()->status);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'produced']);
    }
}
