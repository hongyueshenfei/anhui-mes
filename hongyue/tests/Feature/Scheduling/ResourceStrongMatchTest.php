<?php

namespace Tests\Feature\Scheduling;

use App\Models\Machine;
use App\Models\Order;
use App\Models\PlmRoute;
use App\Models\ProdTask;
use App\Models\Product;
use App\Models\RouteOp;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 资源强对应（客户确认新业务规则）——排产侧
 *
 * - 成型工序拖产线行 → 409 resource_type_mismatch
 * - 喷漆工序拖机台行 → 409 resource_type_mismatch
 * - 产线任务排到产线行成功，并在甘特产线行出现
 * - 工序完整性：缺产线资源 → 拒绝（line_resource_missing）
 * - seed 后资源清单含 3 条产线（P-01 喷漆线 / P-02 效果线 / P-03 包装线）
 */
class ResourceStrongMatchTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /** 建单并返回任务（P-0001 注塑成型→喷漆→效果→包装） */
    private function createMoldingTask(string $productCode = 'P-0001', int $qty = 1900): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '资源强对应测试',
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        $orderId = $response->json('data.id');

        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);
    }

    /**
     * 建纯产线任务（外购白坯：喷漆→包装，无成型工序）
     */
    private function createLineTask(int $qty = 500): ProdTask
    {
        $product = Product::create([
            'code' => 'PL-0001', 'name' => '外购白坯（喷漆包装）', 'unit' => '只', 'status' => 'mass',
        ]);
        $route = PlmRoute::create(['name' => $product->code.' 工艺', 'version' => 'R1', 'status' => PlmRoute::STATUS_PUBLISHED]);
        RouteOp::create([
            'route_id' => $route->id, 'seq' => 10, 'op_type' => RouteOp::OP_PAINT, 'op_name' => '喷漆',
            'mold_change_min' => null, 'shift_mode' => 'day',
            'machine_type' => null,
        ]);
        RouteOp::create([
            'route_id' => $route->id, 'seq' => 20, 'op_type' => RouteOp::OP_PACK, 'op_name' => '包装',
            'mold_change_min' => null, 'shift_mode' => 'day',
            'machine_type' => null,
        ]);
        $product->update(['default_route_id' => $route->id]);

        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '产线任务测试',
            'lines' => [['product_code' => 'PL-0001', 'qty' => $qty]],
        ])->assertStatus(201);

        $orderId = $response->json('data.id');

        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);
    }

    public function test_seed_contains_three_production_lines_in_timeline(): void
    {
        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?start='.now()->format('Y-m-d').'&end='.now()->addDays(7)->format('Y-m-d'))
            ->assertOk()
            ->json('data');

        $machines = collect($timeline['machines']);
        $paintLine = $machines->firstWhere('code', 'P-01');
        $effectLine = $machines->firstWhere('code', 'P-02');
        $packLine = $machines->firstWhere('code', 'P-03');

        $this->assertNotNull($paintLine, '甘特应含 P-01 喷漆线');
        $this->assertSame('paint_line', $paintLine['type']);
        $this->assertSame(1500.0, (float) $paintLine['capacity_per_hour']);
        $this->assertSame('day', $paintLine['shift_mode']);

        $this->assertNotNull($effectLine, '甘特应含 P-02 效果线');
        $this->assertSame('effect_line', $effectLine['type']);
        $this->assertSame(1500.0, (float) $effectLine['capacity_per_hour']);
        $this->assertSame('效果车间', $effectLine['workshop_name']);

        $this->assertNotNull($packLine, '甘特应含 P-03 包装线');
        $this->assertSame('pack_line', $packLine['type']);
        $this->assertSame(3000.0, (float) $packLine['capacity_per_hour']);
    }

    public function test_standard_route_binds_each_line_operation_to_matching_line_and_workshop(): void
    {
        $route = PlmRoute::where('name', '直口杯 500ml标准工艺')->firstOrFail();
        $ops = $route->ops()->whereIn('op_type', [RouteOp::OP_PAINT, RouteOp::OP_EFFECT, RouteOp::OP_PACK])->get()->keyBy('op_type');

        $expected = [
            RouteOp::OP_PAINT => ['line' => 'P-01', 'workshop' => 'paint'],
            RouteOp::OP_EFFECT => ['line' => 'P-02', 'workshop' => 'effect'],
            RouteOp::OP_PACK => ['line' => 'P-03', 'workshop' => 'pack'],
        ];

        foreach ($expected as $opType => $binding) {
            $op = $ops->get($opType);
            $line = Machine::where('code', $binding['line'])->firstOrFail();

            $this->assertNotNull($op, "标准路线缺少 {$opType} 工序");
            $this->assertSame($line->id, (int) $op->default_line_id, "{$opType} 必须绑定对应产线");
            $this->assertSame(
                (int) Workshop::where('code', $binding['workshop'])->value('id'),
                (int) $op->workshop_id,
                "{$opType} 必须绑定对应车间",
            );
            $this->assertSame($line->type, $op->resourceType(), "{$opType} 的工序资源类型必须与产线类型一致");
            $this->assertSame((int) $op->workshop_id, (int) $line->workshop_id, "{$opType} 的产线必须属于工艺车间");
        }
    }

    public function test_molding_task_dropped_on_line_row_rejected_409(): void
    {
        $task = $this->createMoldingTask();
        $paintLine = Machine::where('code', 'P-01')->firstOrFail();

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $paintLine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ]);

        $response->assertStatus(409);
        $codes = collect($response->json('data.errors'))->pluck('code');
        $this->assertContains('resource_type_mismatch', $codes);

        // 错误含工序名与资源清单
        $error = collect($response->json('data.errors'))->firstWhere('code', 'resource_type_mismatch');
        $this->assertNotEmpty($error['op_name'], '错误应含工序名');
        $this->assertNotEmpty($error['resources'], '错误应含资源清单');
        $this->assertSame('paint_line', $error['target_resource']['type']);

        $this->assertDatabaseCount('task_schedules', 0);
    }

    public function test_line_task_dropped_on_machine_row_rejected_409(): void
    {
        $task = $this->createLineTask();
        $injectionMachine = Machine::where('code', 'I-01')->firstOrFail();

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $injectionMachine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ]);

        $response->assertStatus(409);
        $codes = collect($response->json('data.errors'))->pluck('code');
        $this->assertContains('resource_type_mismatch', $codes);

        $error = collect($response->json('data.errors'))->firstWhere('code', 'resource_type_mismatch');
        $this->assertSame('喷漆', $error['op_name'], '首个产线工序名');
        $this->assertSame('paint_line', $error['resource_type']);

        $this->assertDatabaseCount('task_schedules', 0);
    }

    public function test_line_task_scheduled_on_line_row_success_and_appears_in_gantt(): void
    {
        $task = $this->createLineTask(600);
        $paintLine = Machine::where('code', 'P-01')->firstOrFail();
        $packLine = Machine::where('code', 'P-03')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $paintLine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ])->assertOk();

        // 工序完整落库：喷漆→P-01（paint 车间），包装→P-03（pack 车间）
        $this->assertDatabaseHas('task_schedules', [
            'task_id' => $task->id, 'op_type' => 'paint', 'machine_id' => $paintLine->id,
        ]);
        $this->assertDatabaseHas('task_schedules', [
            'task_id' => $task->id, 'op_type' => 'pack', 'machine_id' => $packLine->id,
        ]);
        $this->assertSame(
            (int) Workshop::where('code', 'paint')->value('id'),
            (int) $paintLine->fresh()->workshop_id,
            '喷漆线归属喷漆车间'
        );
        $this->assertSame(
            (int) Workshop::where('code', 'pack')->value('id'),
            (int) $packLine->fresh()->workshop_id,
            '包装线归属包装车间'
        );
        $this->assertSame('scheduled', $task->fresh()->status);

        // 甘特产线行出现排程条
        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?start=2025-09-01&end=2025-09-05')
            ->assertOk()
            ->json('data');

        $machines = collect($timeline['machines']);
        $paintRow = $machines->firstWhere('code', 'P-01');
        $packRow = $machines->firstWhere('code', 'P-03');

        $this->assertNotEmpty(
            collect($paintRow['schedules'])->firstWhere('task_id', $task->id),
            'P-01 喷漆线行应出现该任务喷漆排程'
        );
        $this->assertNotEmpty(
            collect($packRow['schedules'])->firstWhere('task_id', $task->id),
            'P-03 包装线行应出现该任务包装排程'
        );

        // 班组行已下线（产线工序只落产线行 machine_id）
        $this->assertArrayNotHasKey('teams', $timeline, '甘特不再输出班组行');
    }

    public function test_route_bound_effect_line_is_used_instead_of_first_available_line(): void
    {
        $effectWorkshop = Workshop::where('code', 'effect')->firstOrFail();
        $secondEffectLine = Machine::create([
            'code' => 'P-02-B',
            'name' => '效果线 2 号',
            'type' => Machine::TYPE_EFFECT_LINE,
            'workshop_id' => $effectWorkshop->id,
            'capacity_per_hour' => 1500,
            'shift_mode' => 'day',
            'status' => Machine::STATUS_RUNNING,
            'is_active' => 1,
        ]);

        $product = Product::where('code', 'P-0001')->firstOrFail();
        RouteOp::where('route_id', $product->default_route_id)
            ->where('op_type', RouteOp::OP_EFFECT)
            ->update(['default_line_id' => $secondEffectLine->id]);

        $task = $this->createMoldingTask('P-0001', 600);
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => Machine::where('code', 'I-01')->value('id'),
                'plan_start' => '2026-12-01 08:00:00',
            ]],
        ])->assertOk();

        $this->assertSame(
            $secondEffectLine->id,
            (int) TaskSchedule::where('task_id', $task->id)
                ->where('op_type', RouteOp::OP_EFFECT)
                ->value('machine_id'),
            '工艺明确绑定的效果线必须成为实际排程资源'
        );
    }

    public function test_partial_route_snapshot_inherits_standard_resource_binding(): void
    {
        $effectWorkshop = Workshop::where('code', 'effect')->firstOrFail();
        $secondEffectLine = Machine::create([
            'code' => 'P-02-LEGACY',
            'name' => '效果线旧快照兼容测试线',
            'type' => Machine::TYPE_EFFECT_LINE,
            'workshop_id' => $effectWorkshop->id,
            'capacity_per_hour' => 1500,
            'shift_mode' => 'day',
            'status' => Machine::STATUS_RUNNING,
            'is_active' => 1,
        ]);
        $product = Product::where('code', 'P-0001')->firstOrFail();
        RouteOp::where('route_id', $product->default_route_id)
            ->where('op_type', RouteOp::OP_EFFECT)
            ->update(['default_line_id' => $secondEffectLine->id]);

        $task = $this->createMoldingTask('P-0001', 600);
        $task->update([
            'route_ops_override' => collect($task->route->ops)->map(fn (RouteOp $op) => [
                'seq' => $op->seq,
                'op_type' => $op->op_type,
                'op_name' => $op->op_name,
                'workshop_id' => $op->workshop_id,
                // 模拟旧快照：没有保存 machine_id/line_id 等资源字段。
            ])->all(),
        ]);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => Machine::where('code', 'I-01')->value('id'),
                'plan_start' => '2026-12-01 08:00:00',
            ]],
        ])->assertOk();

        $this->assertSame(
            $secondEffectLine->id,
            (int) TaskSchedule::where('task_id', $task->id)
                ->where('op_type', RouteOp::OP_EFFECT)
                ->value('machine_id'),
            '旧工艺快照缺少产线字段时应继承标准路线绑定',
        );
    }

    public function test_route_bound_unavailable_line_is_not_silently_replaced(): void
    {
        $effectLine = Machine::where('code', 'P-02')->firstOrFail();
        $effectLine->update(['status' => Machine::STATUS_MAINTENANCE]);

        $product = Product::where('code', 'P-0001')->firstOrFail();
        RouteOp::where('route_id', $product->default_route_id)
            ->where('op_type', RouteOp::OP_EFFECT)
            ->update(['default_line_id' => $effectLine->id]);

        $task = $this->createMoldingTask('P-0001', 600);
        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => Machine::where('code', 'I-01')->value('id'),
                'plan_start' => '2026-12-01 08:00:00',
            ]],
        ]);

        $response->assertStatus(409);
        $this->assertContains(
            'line_resource_unavailable',
            collect($response->json('data.errors'))->pluck('code')->all(),
            '绑定产线维修时应报明确错误，不能悄悄改排到第一条可用产线'
        );
        $this->assertDatabaseMissing('task_schedules', ['task_id' => $task->id]);
    }

    public function test_unbound_line_fallback_stays_in_operation_workshop(): void
    {
        $effectWorkshop = Workshop::where('code', 'effect')->firstOrFail();
        $line = Machine::create([
            'code' => 'P-01-EFFECT-WS',
            'name' => '效果车间喷漆试验线',
            'type' => Machine::TYPE_PAINT_LINE,
            'workshop_id' => $effectWorkshop->id,
            'capacity_per_hour' => 1200,
            'shift_mode' => 'day',
            'status' => Machine::STATUS_RUNNING,
            'is_active' => 1,
        ]);
        $product = Product::create([
            'code' => 'PL-WS-001',
            'name' => '按车间分配的外购白坯',
            'unit' => '只',
            'status' => 'mass',
        ]);
        $route = PlmRoute::create(['name' => '按车间分配工艺', 'version' => 'R1', 'status' => PlmRoute::STATUS_PUBLISHED]);
        RouteOp::create([
            'route_id' => $route->id,
            'seq' => 1,
            'op_type' => RouteOp::OP_PAINT,
            'op_name' => '喷漆',
            'workshop_id' => $effectWorkshop->id,
            'default_line_id' => null,
            'shift_mode' => 'day',
        ]);
        $product->update(['default_route_id' => $route->id]);

        $created = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '按车间分配客户',
            'lines' => [['product_code' => $product->code, 'qty' => 600]],
        ])->assertStatus(201);
        $orderId = $created->json('data.id');
        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();
        $task = ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);
        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $line->id,
                'plan_start' => '2026-12-01 08:00:00',
            ]],
        ])->assertOk();

        $this->assertSame(
            $line->id,
            (int) TaskSchedule::where('task_id', $task->id)->value('machine_id'),
            '未绑定产线时也必须按工序车间选择兜底资源',
        );
        $this->assertNotSame(
            (int) Machine::where('code', 'P-01')->value('id'),
            (int) TaskSchedule::where('task_id', $task->id)->value('machine_id'),
        );
    }

    public function test_molding_machine_must_match_operation_workshop(): void
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        $paintWorkshop = Workshop::where('code', 'paint')->firstOrFail();
        RouteOp::where('route_id', $product->default_route_id)
            ->where('op_type', RouteOp::OP_MOLDING_INJECTION)
            ->update(['workshop_id' => $paintWorkshop->id]);

        $task = $this->createMoldingTask('P-0001', 600);
        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => Machine::where('code', 'I-01')->value('id'),
                'plan_start' => '2026-12-01 08:00:00',
            ]],
        ]);

        $response->assertStatus(409);
        $this->assertContains(
            'machine_workshop_mismatch',
            collect($response->json('data.errors'))->pluck('code')->all(),
        );
        $this->assertDatabaseMissing('task_schedules', ['task_id' => $task->id]);
    }

    public function test_explicit_recalculate_updates_unfinished_schedule_chain(): void
    {
        $task = $this->createMoldingTask('P-0001', 600);
        $paintLine = Machine::where('code', 'P-01')->firstOrFail();
        $injection = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $injection->id,
                'plan_start' => '2026-12-01 08:00:00',
            ]],
        ])->assertOk();

        $before = TaskSchedule::query()
            ->where('task_id', $task->id)
            ->where('machine_id', $paintLine->id)
            ->firstOrFail()
            ->plan_end;

        $response = $this->api($this->token)->putJson('/api/plm/machines/'.$paintLine->id, [
            'code' => $paintLine->code,
            'name' => $paintLine->name,
            'type' => $paintLine->type,
            'workshop_id' => $paintLine->workshop_id,
            'capacity_per_hour' => 3000,
            'mold_change_min' => $paintLine->mold_change_min,
            'shift_mode' => $paintLine->shift_mode,
            'status' => $paintLine->status,
        ])->assertOk();

        $response->assertJsonPath('data.capacity_per_hour', 3000);
        $after = TaskSchedule::query()
            ->where('task_id', $task->id)
            ->where('machine_id', $paintLine->id)
            ->firstOrFail()
            ->plan_end;

        $this->assertTrue($after->equalTo($before), '仅保存产能时，不应自动改写未开工排程');

        $recalculate = $this->api($this->token)->postJson('/api/schedule/recalculate')->assertOk();
        $this->assertGreaterThan(0, (int) $recalculate->json('data.changed_count'));
        $after = TaskSchedule::query()
            ->where('task_id', $task->id)
            ->where('machine_id', $paintLine->id)
            ->firstOrFail()
            ->plan_end;

        $this->assertTrue($after->lt($before), '提高产能后，未完工工序的计划结束时间应提前');
    }

    public function test_machine_capacity_update_does_not_rewrite_schedule_before_effective_day(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-18 10:00:00'));
        $task = $this->createMoldingTask('P-0001', 600);
        $paintLine = Machine::where('code', 'P-01')->firstOrFail();
        $injection = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $injection->id,
                'plan_start' => '2026-09-17 08:00:00',
            ]],
        ])->assertOk();

        $before = TaskSchedule::where('task_id', $task->id)->get()->keyBy('seq')->map(fn ($row) => [
            'start' => $row->plan_start->format('Y-m-d H:i:s'),
            'end' => $row->plan_end->format('Y-m-d H:i:s'),
        ])->all();

        $this->api($this->token)->putJson('/api/plm/machines/'.$paintLine->id, [
            'code' => $paintLine->code,
            'name' => $paintLine->name,
            'type' => $paintLine->type,
            'workshop_id' => $paintLine->workshop_id,
            'capacity_per_hour' => 3000,
            'mold_change_min' => $paintLine->mold_change_min,
            'shift_mode' => $paintLine->shift_mode,
            'status' => $paintLine->status,
        ])->assertOk();

        $this->api($this->token)->postJson('/api/plm/machines/'.$paintLine->id.'/recalculate-schedules')
            ->assertOk();

        $after = TaskSchedule::where('task_id', $task->id)->get()->keyBy('seq')->map(fn ($row) => [
            'start' => $row->plan_start->format('Y-m-d H:i:s'),
            'end' => $row->plan_end->format('Y-m-d H:i:s'),
        ])->all();
        $this->assertSame($before, $after, '修改产能不能改写修改当天以前的历史排程');
    }

    public function test_capacity_update_does_not_rewrite_already_started_operation(): void
    {
        $task = $this->createMoldingTask('P-0001', 600);
        $injection = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $injection->id,
                'plan_start' => '2026-12-01 08:00:00',
            ]],
        ])->assertOk();

        $molding = TaskSchedule::query()
            ->where('task_id', $task->id)
            ->where('op_type', 'molding_injection')
            ->firstOrFail();
        $originalStart = $molding->plan_start->format('Y-m-d H:i:s');
        $originalEnd = $molding->plan_end->format('Y-m-d H:i:s');
        $molding->update([
            'status' => TaskSchedule::STATUS_RUNNING,
            'actual_start' => '2026-12-01 08:05:00',
        ]);

        $this->api($this->token)->putJson('/api/plm/machines/'.$injection->id, [
            'code' => $injection->code,
            'name' => $injection->name,
            'type' => $injection->type,
            'workshop_id' => $injection->workshop_id,
            'capacity_per_hour' => 800,
            'mold_change_min' => $injection->mold_change_min,
            'shift_mode' => $injection->shift_mode,
            'status' => $injection->status,
        ])->assertOk();

        $this->api($this->token)->postJson('/api/plm/machines/'.$injection->id.'/recalculate-schedules')
            ->assertOk();

        $molding->refresh();
        $this->assertSame($originalStart, $molding->plan_start->format('Y-m-d H:i:s'));
        $this->assertSame($originalEnd, $molding->plan_end->format('Y-m-d H:i:s'));
    }

    public function test_machine_capacity_update_recalculation_preserves_active_task_and_moves_unstarted_task(): void
    {
        $moldingOnly = [[
            'seq' => 1,
            'op_type' => RouteOp::OP_MOLDING_INJECTION,
            'op_name' => '注塑成型',
            'qty' => 600,
        ]];
        $taskA = $this->createMoldingTask('P-0001', 600);
        $taskB = $this->createMoldingTask('P-0001', 600);
        $taskA->update(['route_ops_override' => $moldingOnly]);
        $taskB->update(['route_ops_override' => $moldingOnly]);

        $injection = Machine::where('code', 'I-01')->firstOrFail();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskA->id,
                'machine_id' => $injection->id,
                'plan_start' => '2026-12-01 08:00:00',
            ]],
        ])->assertOk();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskB->id,
                'machine_id' => $injection->id,
                'plan_start' => '2026-12-01 12:00:00',
            ]],
        ])->assertOk();

        $taskB->fresh()->schedules()->firstOrFail()->update([
            'status' => TaskSchedule::STATUS_RUNNING,
            'actual_start' => '2026-12-01 12:05:00',
        ]);
        $response = $this->api($this->token)->putJson('/api/plm/machines/'.$injection->id, [
            'code' => $injection->code,
            'name' => $injection->name,
            'type' => $injection->type,
            'workshop_id' => $injection->workshop_id,
            'capacity_per_hour' => 100,
            'mold_change_min' => $injection->mold_change_min,
            'shift_mode' => $injection->shift_mode,
            'status' => $injection->status,
        ]);

        $response->assertOk();
        $this->assertSame(100.0, (float) $injection->fresh()->capacity_per_hour);

        $response = $this->api($this->token)->postJson('/api/plm/machines/'.$injection->id.'/recalculate-schedules');
        $response->assertOk();
        $taskAStart = $taskA->fresh()->schedules()->firstOrFail()->plan_start;
        $taskBEnd = $taskB->fresh()->schedules()->firstOrFail()->plan_end;
        $this->assertGreaterThanOrEqual(
            $taskBEnd->getTimestamp(),
            $taskAStart->getTimestamp(),
            '重算时已开工任务保持不动，未开工任务应顺延到已开工任务之后',
        );
        $this->assertSame(
            100.0,
            (float) $injection->fresh()->capacity_per_hour,
            '重算过程中，已保存的机台产能不应回滚'
        );
    }

    public function test_capacity_update_does_not_rewrite_schedule_with_legacy_report_ledger(): void
    {
        $moldingOnly = [[
            'seq' => 1,
            'op_type' => RouteOp::OP_MOLDING_INJECTION,
            'op_name' => '注塑成型',
            'qty' => 600,
        ]];
        $task = $this->createMoldingTask('P-0001', 600);
        $task->update(['route_ops_override' => $moldingOnly]);
        $injection = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $injection->id,
                'plan_start' => '2026-12-05 08:00:00',
            ]],
        ])->assertOk();

        $schedule = $task->fresh()->schedules()->firstOrFail();
        $originalStart = $schedule->plan_start->format('Y-m-d H:i:s');
        $originalEnd = $schedule->plan_end->format('Y-m-d H:i:s');
        // 模拟旧数据：有报工流水但没有 actual_start/status 联动字段。
        WorkReport::create([
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'product_id' => $task->product_id,
            'machine_id' => $injection->id,
            'qty' => 100,
            'defect_qty' => 0,
            'sign' => 1,
            'report_by' => 1,
            'worker_id' => null,
            'report_time' => '2026-12-05 09:00:00',
            'work_date' => '2026-12-05',
            'source' => WorkReport::SOURCE_LEADER_BATCH,
        ]);

        $this->api($this->token)->putJson('/api/plm/machines/'.$injection->id, [
            'code' => $injection->code,
            'name' => $injection->name,
            'type' => $injection->type,
            'workshop_id' => $injection->workshop_id,
            'capacity_per_hour' => 100,
            'mold_change_min' => $injection->mold_change_min,
            'shift_mode' => $injection->shift_mode,
            'status' => $injection->status,
        ])->assertOk();

        $schedule->refresh();
        $this->assertSame($originalStart, $schedule->plan_start->format('Y-m-d H:i:s'));
        $this->assertSame($originalEnd, $schedule->plan_end->format('Y-m-d H:i:s'));
    }

    public function test_missing_line_resource_rejected_missing_op(): void
    {
        // 缺工序拒绝：任务工艺含喷漆工序，但喷漆产线被停用 → 无法完成全部工序链 → 409
        Machine::where('code', 'P-01')->update(['is_active' => 0]);

        $task = $this->createMoldingTask();
        $injectionMachine = Machine::where('code', 'I-01')->firstOrFail();

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $injectionMachine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ]);

        $response->assertStatus(409);
        $codes = collect($response->json('data.errors'))->pluck('code');
        $this->assertContains('line_resource_unavailable', $codes);
        $this->assertDatabaseCount('task_schedules', 0);
    }

    public function test_route_without_ops_rejected(): void
    {
        // 工艺路线无工序 → 无法排产（no_ops）
        $product = Product::create([
            'code' => 'PN-0001', 'name' => '无工序产品', 'unit' => '只', 'status' => 'mass',
        ]);
        $route = PlmRoute::create(['name' => $product->code.' 工艺', 'version' => 'R1', 'status' => PlmRoute::STATUS_PUBLISHED]);
        $product->update(['default_route_id' => $route->id]);

        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '无工序客户',
            'lines' => [['product_code' => 'PN-0001', 'qty' => 100]],
        ])->assertStatus(201);
        $task = ProdTask::firstWhere('order_line_id', Order::find($response->json('data.id'))->lines->first()->id);
        $this->api($this->token)->postJson('/api/orders/'.$response->json('data.id').'/route-confirm')->assertOk();
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $save = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ]);
        $save->assertStatus(409);
        $this->assertContains('no_ops', collect($save->json('data.errors'))->pluck('code')->all());
    }
}
