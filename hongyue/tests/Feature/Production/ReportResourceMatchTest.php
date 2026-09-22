<?php

namespace Tests\Feature\Production;

use App\Models\Machine;
use App\Models\Mold;
use App\Models\Order;
use App\Models\PlmRoute;
use App\Models\ProdTask;
use App\Models\Product;
use App\Models\RouteOp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 资源强对应（客户确认新业务规则）——报工侧
 *
 * - 当前仅班组长/统计员可报工，工人自报统一关闭
 * - 新报工不再记录 worker_id，资源关联由 schedule_id/machine_id 保证
 */
class ReportResourceMatchTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /**
     * 建「注塑成型」单工序任务（免喷漆/包装），排到 I-01
     */
    private function createMoldingOnlyTask(): ProdTask
    {
        $product = Product::create([
            'code' => 'PM-0001', 'name' => '注塑白坯（免喷漆）', 'unit' => '只', 'status' => 'mass',
        ]);
        $mold = Mold::create([
            'code' => 'MM-001', 'name' => '白坯注塑模', 'type' => 'injection', 'status' => 'idle',
            'cavity_count' => 2, 'lifetime_openings' => 100000,
        ]);
        $mold->products()->attach($product->id);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $mold->machines()->attach($machine->id);

        $route = PlmRoute::create(['name' => $product->code.' 工艺', 'version' => 'R1', 'status' => PlmRoute::STATUS_PUBLISHED]);
        RouteOp::create([
            'route_id' => $route->id, 'seq' => 10, 'op_type' => RouteOp::OP_MOLDING_INJECTION, 'op_name' => '注塑成型',
            'mold_change_min' => 15, 'shift_mode' => 'two_shift',
            'machine_type' => Machine::TYPE_INJECTION,
        ]);
        $product->update(['default_mold_id' => $mold->id, 'default_route_id' => $route->id]);

        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '报工资源测试',
            'lines' => [['product_code' => 'PM-0001', 'qty' => 1000]],
        ])->assertStatus(201);
        $task = ProdTask::firstWhere('order_line_id', Order::find($response->json('data.id'))->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$response->json('data.id').'/route-confirm')->assertOk();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => now()->addDays(2)->startOfDay()->addHours(8)->format('Y-m-d H:i:s')]],
        ])->assertOk();

        return $task->fresh();
    }

    /**
     * 建纯产线任务（喷漆→包装），排到 P-01 喷漆线
     */
    private function createLineTask(): ProdTask
    {
        $product = Product::create([
            'code' => 'PL-0001', 'name' => '外购白坯（喷漆包装）', 'unit' => '只', 'status' => 'mass',
        ]);
        $route = PlmRoute::create(['name' => $product->code.' 工艺', 'version' => 'R1', 'status' => PlmRoute::STATUS_PUBLISHED]);
        RouteOp::create([
            'route_id' => $route->id, 'seq' => 10, 'op_type' => RouteOp::OP_PAINT, 'op_name' => '喷漆',
            'mold_change_min' => null, 'shift_mode' => 'day',
        ]);
        RouteOp::create([
            'route_id' => $route->id, 'seq' => 20, 'op_type' => RouteOp::OP_PACK, 'op_name' => '包装',
            'mold_change_min' => null, 'shift_mode' => 'day',
        ]);
        $product->update(['default_route_id' => $route->id]);

        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '产线报工测试',
            'lines' => [['product_code' => 'PL-0001', 'qty' => 500]],
        ])->assertStatus(201);
        $task = ProdTask::firstWhere('order_line_id', Order::find($response->json('data.id'))->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$response->json('data.id').'/route-confirm')->assertOk();

        $paintLine = Machine::where('code', 'P-01')->firstOrFail();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $paintLine->id, 'plan_start' => now()->addDays(3)->startOfDay()->addHours(8)->format('Y-m-d H:i:s')]],
        ])->assertOk();

        return $task->fresh();
    }

    public function test_worker_report_is_disabled(): void
    {
        $task = $this->createMoldingOnlyTask(); // 仅注塑机台资源

        // 工人统一不能提交报工
        $packWorker = $this->loginToken('W004');
        $resp = $this->api($packWorker)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 100]);
        $resp->assertStatus(403);

        // 不同车间工人同样不能提交
        $paintWorker = $this->loginToken('W003');
        $this->api($paintWorker)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 100])
            ->assertStatus(403);

        $this->assertDatabaseCount('work_reports', 0);
    }

    public function test_leader_report_keeps_schedule_resource_match(): void
    {
        $task = $this->createMoldingOnlyTask();

        // 班组长按排程资源统一填报，成功且不写个人作业人
        $leader = $this->loginToken('L001');
        $this->api($leader)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 80, 'schedule_id' => $task->schedules()->first()->id])
            ->assertStatus(201);

        $this->assertDatabaseHas('work_reports', ['task_id' => $task->id, 'qty' => 80, 'sign' => 1]);
        $this->assertDatabaseMissing('work_reports', ['task_id' => $task->id, 'worker_id' => 1]);
        $this->assertSame(80, $task->fresh()->done_qty);
    }

    public function test_leader_report_does_not_accept_worker_attribution(): void
    {
        $task = $this->createMoldingOnlyTask();

        $leader = $this->loginToken('L001');

        // 暂不允许继续传入实际作业人
        $packWorker = User::where('employee_no', 'W004')->firstOrFail();
        $this->api($leader)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 50, 'source' => 'leader_report', 'worker_id' => $packWorker->id,
        ])->assertStatus(422)->assertJsonPath('data.error_code', 'worker_attribution_disabled');

        // 不带 worker_id 直接成功
        $resp = $this->api($leader)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 50, 'source' => 'leader_report',
        ]);
        $resp->assertStatus(201);

        $report = \App\Models\WorkReport::latest('id')->first();
        $this->assertNull($report->worker_id, '新报工不再记录个人作业人');
    }

    public function test_line_report_requires_report_operator(): void
    {
        $task = $this->createLineTask(); // 喷漆→P-01(paint)，包装→P-03(pack)

        // 工人不再能提交产线报工
        $this->api($this->loginToken('W003'))->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 60])
            ->assertStatus(403);

        // 班组长统一提交两笔，仍按任务/排程链路累计
        $leader = $this->loginToken('L001');
        $this->api($leader)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 60])
            ->assertStatus(201);
        $this->api($leader)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 40])
            ->assertStatus(201);

        $this->assertSame(100, $task->fresh()->done_qty);
    }
}
