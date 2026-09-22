<?php

namespace Tests\Feature\Scheduling;

use App\Models\Machine;
use App\Models\Mold;
use App\Models\Order;
use App\Models\PlmRoute;
use App\Models\ProdTask;
use App\Models\Product;
use App\Models\RouteOp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 产品-模具-机台三匹配校验测试（二期验收 B8）
 * - 模具-产品不匹配 → 拒绝
 * - 模具-机台不匹配 → 拒绝
 * - 机台-工艺类型不匹配 → 拒绝
 * - force 强制排入不豁免三匹配（D7）
 */
class TripleMatchTest extends TestCase
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
            'customer_name' => '三匹配测试',
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        $orderId = $response->json('data.id');
        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产（订单-工艺-排产流程）
        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);
    }

    public function test_mold_machine_mismatch_rejected(): void
    {
        // P-0001 默认模具 M-001 仅适用 I-01~I-04；拖到吹塑机 B-01 → 拒绝
        $task = $this->createTask('P-0001');
        $blowMachine = Machine::where('code', 'B-01')->first();

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $blowMachine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ]);

        $response->assertStatus(409);
        $codes = collect($response->json('data.errors'))->pluck('code');
        $this->assertContains('mold_machine_mismatch', $codes);
        $this->assertContains('resource_type_mismatch', $codes, '注塑任务拖到吹塑机必须同时被资源类型约束拦截');
        $this->assertDatabaseCount('task_schedules', 0);
    }

    public function test_mold_product_mismatch_rejected(): void
    {
        // P-0001 显式指定 M-002（圆瓶模具，未关联直口杯产品）→ 拒绝
        $task = $this->createTask('P-0001');
        $machine = Machine::where('code', 'I-01')->first();
        $wrongMold = Mold::where('code', 'M-002')->first();

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00', 'mold_id' => $wrongMold->id]],
        ]);

        $response->assertStatus(409);
        $codes = collect($response->json('data.errors'))->pluck('code');
        $this->assertContains('mold_product_mismatch', $codes);
    }

    public function test_task_without_mold_rejected(): void
    {
        // 新建产品无默认模具 → 待排任务无模具 → 拒绝
        $product = Product::create([
            'code' => 'PX-0001', 'name' => '无模具产品', 'unit' => '只', 'status' => 'mass',
        ]);
        // 补已发布工艺路线（无模具 ≠ 无工艺；工艺确认要求路线已配置且已发布）
        $route = PlmRoute::create(['name' => $product->code.' 工艺', 'version' => 'R1', 'status' => PlmRoute::STATUS_PUBLISHED]);
        RouteOp::create([
            'route_id' => $route->id, 'seq' => 10, 'op_type' => RouteOp::OP_MOLDING_INJECTION, 'op_name' => '注塑成型',
            'mold_change_min' => 15, 'shift_mode' => 'two_shift',
        ]);
        $product->update(['default_route_id' => $route->id]);

        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '无模具客户',
            'lines' => [['product_code' => 'PX-0001', 'qty' => 100]],
        ])->assertStatus(201);

        $orderId = $response->json('data.id');
        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产
        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();
        $task = ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);
        $machine = Machine::where('code', 'I-01')->first();

        $save = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ]);

        $save->assertStatus(409);
        $codes = collect($save->json('data.errors'))->pluck('code');
        $this->assertContains('no_mold', $codes);
    }

    public function test_machine_type_mismatch_rejected(): void
    {
        // 产品 PX-0002 + 模具 MX-002（适用 I-01），但工艺成型工序要求 blow 机台 → 拒绝
        $product = Product::create([
            'code' => 'PX-0002', 'name' => '类型不匹配产品', 'unit' => '只', 'status' => 'mass',
        ]);
        $mold = Mold::create([
            'code' => 'MX-002', 'name' => '类型不匹配模具', 'type' => 'injection', 'status' => 'idle',
            'cavity_count' => 1, 'lifetime_openings' => 100000,
        ]);
        $mold->products()->attach($product->id);
        $machine = Machine::where('code', 'I-01')->first();
        $mold->machines()->attach($machine->id);

        $route = PlmRoute::create(['name' => $product->code.' 工艺', 'version' => 'V1.0', 'status' => 'published']);
        RouteOp::create([
            'route_id' => $route->id, 'seq' => 10, 'op_type' => 'molding_injection', 'op_name' => '注塑成型',
            'mold_change_min' => 15, 'machine_type' => 'blow', 'shift_mode' => 'two_shift',
        ]);
        $product->update(['default_mold_id' => $mold->id, 'default_route_id' => $route->id]);

        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '类型客户',
            'lines' => [['product_code' => 'PX-0002', 'qty' => 500]],
        ])->assertStatus(201);
        $orderId = $response->json('data.id');
        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产
        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();
        $task = ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);

        $save = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ]);

        $save->assertStatus(409);
        $codes = collect($save->json('data.errors'))->pluck('code');
        $this->assertContains('machine_type_mismatch', $codes);
    }

    public function test_force_does_not_bypass_triple_match(): void
    {
        $task = $this->createTask('P-0001');
        $blowMachine = Machine::where('code', 'B-01')->first();

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $blowMachine->id, 'plan_start' => '2025-08-01 08:00:00']],
            'force' => true,
        ]);

        $response->assertStatus(409); // force 只豁免时间冲突，不豁免三匹配（D7）
        $this->assertDatabaseCount('task_schedules', 0);
    }

    public function test_non_running_machine_cannot_be_scheduled(): void
    {
        $task = $this->createTask('P-0001');
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $machine->update(['status' => Machine::STATUS_MAINTENANCE]);

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('data.errors.0.code', 'machine_inactive');
        $response->assertJsonPath('data.errors.0.machine_status', Machine::STATUS_MAINTENANCE);
        $this->assertStringContainsString('维修中', $response->json('data.errors.0.message'));
        $this->assertDatabaseCount('task_schedules', 0);
    }

    public function test_repair_or_scrapped_mold_cannot_be_scheduled(): void
    {
        $task = $this->createTask('P-0001');
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $mold = Mold::where('code', 'M-001')->firstOrFail();

        foreach ([Mold::STATUS_REPAIR, Mold::STATUS_SCRAPPED] as $status) {
            $mold->update(['status' => $status]);

            $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
                'items' => [[
                    'task_id' => $task->id,
                    'machine_id' => $machine->id,
                    'plan_start' => '2025-08-01 08:00:00',
                ]],
            ]);

            $response->assertStatus(409);
            $response->assertJsonPath('data.errors.0.code', 'mold_unavailable');
            $response->assertJsonPath('data.errors.0.mold_status', $status);
            $this->assertStringContainsString(Mold::STATUS_LABELS[$status], $response->json('data.errors.0.message'));
        }

        $this->assertDatabaseCount('task_schedules', 0);
    }

    public function test_valid_triple_match_saves(): void
    {
        $task = $this->createTask('P-0001');
        $machine = Machine::where('code', 'I-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $this->assertDatabaseHas('task_schedules', ['task_id' => $task->id, 'machine_id' => $machine->id]);
    }
}
