<?php

namespace Tests\Feature\Plm;

use App\Models\Machine;
use App\Models\Mold;
use App\Models\Order;
use App\Models\PlmRoute;
use App\Models\ProdTask;
use App\Models\Product;
use App\Models\RouteOp;
use App\Models\User;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 班组下线 · 车间绑定（用户需求：先有车间，车间下面是机台或产线）
 *
 * - 机台/产线必须归属车间（workshop_id required，缺省 422）
 * - 工人按车间维度报工（同车间成功，跨车间 422）
 * - 产线排程只写 machine_id（task_schedules 已无 team_id 列）
 * - users.workshop_id 种子回填正确（工人/班组长归车间，管理岗为空）
 */
class WorkshopBindingTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function injectionId(): int
    {
        return (int) Workshop::where('code', 'injection')->firstOrFail()->id;
    }

    /**
     * 1) 机台必归车间：workshop_id 缺省 → 422，不落库
     */
    public function test_machine_requires_workshop_id(): void
    {
        $response = $this->api($this->token)->postJson('/api/plm/machines', [
            'code' => 'I-10', 'name' => '缺车间机台', 'type' => 'injection',
            'capacity_per_hour' => 400, 'mold_change_min' => 30, 'shift_mode' => 'two_shift', 'status' => 'running',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('workshop_id', $response->json('data'));
        $this->assertDatabaseMissing('machines', ['code' => 'I-10']);
    }

    /**
     * 2) 产线同样必归车间（paint_line/effect_line/pack_line 不再绑班组，绑车间）
     */
    public function test_line_also_requires_workshop_id(): void
    {
        $response = $this->api($this->token)->postJson('/api/plm/machines', [
            'code' => 'P-09', 'name' => '缺车间产线', 'type' => 'paint_line',
            'capacity_per_hour' => 100, 'mold_change_min' => 0, 'shift_mode' => 'day', 'status' => 'running',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('machines', ['code' => 'P-09']);
    }

    /**
     * 3) 带 workshop_id 的机台创建成功，且落库 workshop_id
     */
    public function test_machine_with_workshop_id_created(): void
    {
        $response = $this->api($this->token)->postJson('/api/plm/machines', [
            'code' => 'I-11', 'name' => '注塑机 11 号', 'type' => 'injection',
            'workshop_id' => $this->injectionId(),
            'capacity_per_hour' => 400, 'mold_change_min' => 30, 'shift_mode' => 'two_shift', 'status' => 'running',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('machines', ['code' => 'I-11', 'workshop_id' => $this->injectionId()]);
    }

    /**
     * 4) 当前暂停工人自报：统一由班组长/统计员按排程报工
     */
    public function test_worker_reports_by_workshop_dimension(): void
    {
        $product = Product::create([
            'code' => 'WB-0001', 'name' => '注塑白坯（车间报工）', 'unit' => '只', 'status' => 'mass',
        ]);
        $mold = Mold::create([
            'code' => 'WB-001', 'name' => '车间报工模', 'type' => 'injection', 'status' => 'idle',
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
            'customer_name' => '车间报工测试',
            'lines' => [['product_code' => 'WB-0001', 'qty' => 1000]],
        ])->assertStatus(201);
        $task = ProdTask::firstWhere('order_line_id', Order::find($response->json('data.id'))->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$response->json('data.id').'/route-confirm')->assertOk();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => now()->addDays(2)->startOfDay()->addHours(8)->format('Y-m-d H:i:s')]],
        ])->assertOk();

        // 工人自报统一关闭
        $this->api($this->loginToken('W001'))->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 80])
            ->assertStatus(403);

        // 班组长按排程统一填报
        $this->api($this->loginToken('L001'))->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 80])
            ->assertStatus(201);

        // 包装车间工人同样禁止
        $this->api($this->loginToken('W004'))->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 30])
            ->assertStatus(403);

        $this->assertSame(80, $task->fresh()->done_qty);
    }

    /**
     * 5) 产线排程只写 machine_id：task_schedules 已无 team_id 列，产线工序落产线行
     */
    public function test_line_schedule_writes_machine_id_only(): void
    {
        $this->assertFalse(Schema::hasColumn('task_schedules', 'team_id'), 'task_schedules 不应再有 team_id 列');

        $product = Product::create([
            'code' => 'WB-0002', 'name' => '外购白坯（喷漆包装）', 'unit' => '只', 'status' => 'mass',
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
            'customer_name' => '产线排程测试',
            'lines' => [['product_code' => 'WB-0002', 'qty' => 500]],
        ])->assertStatus(201);
        $task = ProdTask::firstWhere('order_line_id', Order::find($response->json('data.id'))->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$response->json('data.id').'/route-confirm')->assertOk();

        $paintLine = Machine::where('code', 'P-01')->firstOrFail();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $paintLine->id, 'plan_start' => now()->addDays(3)->startOfDay()->addHours(8)->format('Y-m-d H:i:s')]],
        ])->assertOk();

        $this->assertDatabaseHas('task_schedules', [
            'task_id' => $task->id, 'op_type' => 'paint', 'machine_id' => $paintLine->id,
        ]);
        $this->assertDatabaseHas('task_schedules', [
            'task_id' => $task->id, 'op_type' => 'pack', 'machine_id' => Machine::where('code', 'P-03')->value('id'),
        ]);
    }

    /**
     * 6) users.workshop_id 回填：工人/班组长归车间，管理岗为空
     */
    public function test_users_workshop_id_seeded(): void
    {
        $injection = Workshop::where('code', 'injection')->firstOrFail();
        $this->assertSame($injection->id, (int) User::where('employee_no', 'W001')->firstOrFail()->workshop_id, 'W001 归注塑车间');
        $this->assertSame($injection->id, (int) User::where('employee_no', 'L001')->firstOrFail()->workshop_id, 'L001 班组长归注塑车间');
        $this->assertSame(
            (int) Workshop::where('code', 'paint')->value('id'),
            (int) User::where('employee_no', 'W003')->firstOrFail()->workshop_id,
            'W003 归喷漆车间'
        );
        $this->assertSame(
            (int) Workshop::where('code', 'pack')->value('id'),
            (int) User::where('employee_no', 'W004')->firstOrFail()->workshop_id,
            'W004 归包装车间'
        );
        $this->assertNull(User::where('employee_no', 'E001')->firstOrFail()->workshop_id, '管理岗无车间');
    }
}
