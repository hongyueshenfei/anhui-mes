<?php

namespace Tests\Feature\Scheduling;

use App\Models\Machine;
use App\Models\Order;
use App\Models\PlmRoute;
use App\Models\ProdTask;
use App\Models\RouteOp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 排产结束时间计算测试（二期验收 B2 后端口径）
 *
 * 种子口径（ProductSeeder/WorkshopSeeder）：
 * - P-0001 直口杯 500ml：成型（工序无产能，按机台 I-01 400/h）+ 换模 30min（two_shift）→ 喷漆（P-01 1500/h, day）→ 效果（P-02 1500/h, day）→ 包装（P-03 3000/h, day）
 * - P-0002 圆瓶 1L：成型（工序无产能，按机台 B-01 300/h）+ 换模 30min（two_shift）
 */
class EndTimeCalculationTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function createTask(string $productCode = 'P-0001', int $qty = 1900): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '排产测试',
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        $orderId = $response->json('data.id');
        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产（订单-工艺-排产流程）
        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);
    }

    public function test_molding_end_time_uses_route_capacity_plus_mold_change(): void
    {
        $task = $this->createTask('P-0001', 1900); // 机台 I-01 400/h → 4.75h 加工 + 0.5h 换模
        $machine = Machine::where('code', 'I-01')->first();

        // 工序不设产能：按机台 400/h + 固定换模 30min，再加 5% 预留 = 13:30:45
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $molding = $task->fresh()->schedules()->where('op_type', 'molding_injection')->first();

        $this->assertSame('2025-08-01 08:00:00', $molding->plan_start->format('Y-m-d H:i:s'));
        $this->assertSame('2025-08-01 13:30:45', $molding->plan_end->format('Y-m-d H:i:s'), '（4.75h 加工 + 30min 换模）× 1.05 = 13:30:45');
    }

    public function test_full_operation_chain_is_sequential(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $schedules = $task->fresh()->schedules()->orderBy('seq')->get();

        $this->assertCount(4, $schedules, 'P-0001 工艺含成型/喷漆/效果/包装 4 道工序');
        $this->assertSame(['molding_injection', 'paint', 'effect', 'pack'], $schedules->pluck('op_type')->all());

        // 链式顺序：每道工序从上一道结束开始
        foreach ($schedules as $i => $schedule) {
            if ($i > 0) {
                $this->assertGreaterThanOrEqual(
                    $schedules[$i - 1]->plan_end->getTimestamp(),
                    $schedule->plan_start->getTimestamp(),
                    "工序 {$schedule->op_type} 应在上道工序结束后开始"
                );
            }
            $this->assertTrue($schedule->plan_end->gt($schedule->plan_start));
        }

        // 成型（two_shift 24h）→ 喷漆（P-01 1500/h, day，含 5% 预留）
        $paint = $schedules->firstWhere('op_type', 'paint');
        $this->assertSame('2025-08-01 14:50:33', $paint->plan_end->format('Y-m-d H:i:s'), '喷漆按 1500/h 计算，从 13:30:45 起继续白班生产');

        // 任务级 plan = 首/末工序
        $task = $task->fresh();
        $this->assertSame('2025-08-01 08:00:00', $task->plan_start->format('Y-m-d H:i:s'));
        $this->assertSame('2025-08-01 16:50:15', $task->plan_end->format('Y-m-d H:i:s'));
    }

    public function test_downstream_operations_wait_for_resource_gaps_instead_of_conflicting(): void
    {
        $firstTask = $this->createTask('P-0001', 1900);
        $secondTask = $this->createTask('P-0003', 1900);
        $firstMachine = Machine::where('code', 'I-01')->firstOrFail();
        $secondMachine = Machine::where('code', 'I-02')->firstOrFail();

        // 两个任务的成型分别落在两台注塑机，但后续共用喷漆/效果/包装线。
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $firstTask->id, 'machine_id' => $firstMachine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        // 第二个任务的首道成型按拖入位置固定；后续工序应等待公共产线空档，而不是直接报冲突。
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $secondTask->id, 'machine_id' => $secondMachine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $firstPaint = $firstTask->fresh()->schedules()->where('op_type', 'paint')->firstOrFail();
        $secondSchedules = $secondTask->fresh()->schedules()->orderBy('seq')->get();
        $secondMolding = $secondSchedules->firstWhere('op_type', 'molding_injection');
        $secondPaint = $secondSchedules->firstWhere('op_type', 'paint');

        $this->assertSame('2025-08-01 08:00:00', $secondMolding->plan_start->format('Y-m-d H:i:s'));
        $this->assertGreaterThanOrEqual(
            $firstPaint->plan_end->copy()->addMinutes(30)->getTimestamp(),
            $secondPaint->plan_start->getTimestamp(),
            '后续喷漆工序应在公共喷漆线空档开始，并保留切换间隔'
        );
    }

    public function test_two_shift_production_is_continuous_24h(): void
    {
        $task = $this->createTask('P-0002', 2800); // 机台 B-01 300/h → 9.33h + 0.5h 换模 ≈ 9.83h
        $machine = Machine::where('code', 'B-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-03 08:00:00']],
        ])->assertOk();

        $molding = $task->fresh()->schedules()->where('op_type', 'molding_blow')->first();

        // 两班制 24 小时连续：9.83h 加 5% 预留后为 10.32h
        $this->assertSame('2025-08-03 18:19:30', $molding->plan_end->format('Y-m-d H:i:s'));
    }

    public function test_task_pool_shows_estimated_hours(): void
    {
        $task = $this->createTask('P-0001', 1900);

        $pool = $this->api($this->token)->getJson('/api/schedule/task-pool');
        $pool->assertOk();

        $item = collect($pool->json('data.items'))->firstWhere('id', $task->id);
        $this->assertNotNull($item);
        $this->assertTrue($item['estimate_ok']);
        $this->assertEqualsWithDelta(5.51, $item['estimated_hours'], 0.01, '1900 件 @机台 400/h + 30min 换模，再加 5% 预留');
    }

    public function test_task_pool_exposes_first_operation_resource_type(): void
    {
        $task = $this->createTask('P-0002', 1900);

        $pool = $this->api($this->token)->getJson('/api/schedule/task-pool')->assertOk();
        $item = collect($pool->json('data.items'))->firstWhere('id', $task->id);

        $this->assertSame('molding_blow', $item['required_op_type']);
        $this->assertSame('吹塑成型', $item['required_op_name']);
        $this->assertSame('blow', $item['required_resource_type']);
        $this->assertSame('吹塑机', $item['required_resource_label']);
        $this->assertGreaterThan(0, $item['route_ops_count']);
    }

    public function test_task_pool_excludes_published_route_without_operations(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $emptyRoute = PlmRoute::create([
            'name' => '空工序路线 '.uniqid(),
            'version' => 'R1',
            'status' => PlmRoute::STATUS_PUBLISHED,
        ]);
        $task->update(['route_id' => $emptyRoute->id]);

        $pool = $this->api($this->token)->getJson('/api/schedule/task-pool')->assertOk()->json('data.items');

        $this->assertNotContains($task->id, collect($pool)->pluck('id')->all(), '没有工序的任务不能进入待排任务池');
    }

    public function test_task_pool_estimate_uses_fastest_compatible_machine(): void
    {
        $task = $this->createTask('P-0001', 1900);
        Machine::where('code', 'I-02')->update(['capacity_per_hour' => 800]);

        $pool = $this->api($this->token)->getJson('/api/schedule/task-pool')->assertOk();
        $item = collect($pool->json('data.items'))->firstWhere('id', $task->id);

        $this->assertTrue($item['estimate_ok']);
        $this->assertEqualsWithDelta(3.02, $item['estimated_hours'], 0.01, '任务池预估应随可用机台最新产能变化');
    }

    public function test_task_pool_estimate_uses_product_default_mold_when_task_mold_is_empty(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $task->update(['mold_id' => null]);
        $mold = $task->product->defaultMold;
        $incompatible = Machine::where('code', 'I-02')->firstOrFail();
        $mold->machines()->detach($incompatible->id);

        $pool = $this->api($this->token)->getJson('/api/schedule/task-pool')->assertOk();
        $item = collect($pool->json('data.items'))->firstWhere('id', $task->id);

        $this->assertTrue($item['estimate_ok']);
        $this->assertSame($mold->id, (int) $item['mold_id']);
        $this->assertSame($mold->code, $item['mold_code']);
        $this->assertEqualsWithDelta(5.51, $item['estimated_hours'], 0.01, '任务池预估应按产品默认模具筛选可用机台');
    }

    public function test_task_pool_estimate_uses_order_operation_quantity(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $task->update([
            'route_ops_override' => [[
                'seq' => 1,
                'op_type' => RouteOp::OP_MOLDING_INJECTION,
                'op_name' => '注塑成型',
                'qty' => 800,
            ]],
        ]);

        $pool = $this->api($this->token)->getJson('/api/schedule/task-pool')->assertOk();
        $item = collect($pool->json('data.items'))->firstWhere('id', $task->id);

        $this->assertTrue($item['estimate_ok']);
        $this->assertEqualsWithDelta(2.63, $item['estimated_hours'], 0.01, '任务池预估应按工单成型工序数量，而非任务总数量');
    }
}
