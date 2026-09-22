<?php

namespace Tests\Feature\Plm;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PlmRoute;
use App\Models\Product;
use App\Models\ProdTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 工艺路线编辑生命周期（2026-08-31 业务确认，二次修订）
 *
 * 工艺路线与生产订单完全脱离关联：
 * - 路线（名称/版本/说明/工序）可随时编辑，无状态与引用限制
 * - 订单/排程在第一次选择路线时已快照工序配置（task_schedules 冗余字段），
 *   后续路线编辑不影响已生成排程数据
 * - 名称+版本唯一
 */
class RouteEditLifecycleTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /**
     * 已发布路线：名称/版本/工序均可编辑
     */
    public function test_published_route_can_edit_header_and_ops(): void
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        $route = $product->getPublishedRoute();
        $this->assertNotNull($route);
        $machine = \App\Models\Machine::where('code', 'I-01')->firstOrFail();

        // 头部信息（名称/版本）允许修改
        $response = $this->api($this->token)->putJson("/api/plm/routes/{$route->id}", [
            'name' => '直口杯改名工艺',
            'version' => 'R2',
        ]);
        $response->assertOk()
            ->assertJsonPath('data.name', '直口杯改名工艺')
            ->assertJsonPath('data.version', 'R2');

        // 已发布路线工序同样允许修改（与已生成排程脱离关联）
        $updated = $this->api($this->token)->putJson("/api/plm/routes/{$route->id}", [
            'name' => '直口杯改名工艺',
            'version' => 'R2',
            'ops' => [
                ['seq' => 1, 'op_type' => 'molding_injection', 'op_name' => '注塑成型', 'workshop_id' => $machine->workshop_id, 'mold_change_min' => 45, 'shift_mode' => 'two_shift', 'machine_type' => 'injection', 'default_machine_id' => $machine->id],
                ['seq' => 2, 'op_type' => 'paint', 'op_name' => '喷漆', 'workshop_id' => \App\Models\Machine::where('code', 'P-01')->firstOrFail()->workshop_id, 'shift_mode' => 'day', 'machine_type' => null, 'default_line_id' => \App\Models\Machine::where('code', 'P-01')->firstOrFail()->id],
            ],
        ]);
        $updated->assertOk()->assertJsonCount(2, 'data.ops');
    }

    /**
     * 被生产任务引用的路线：可正常编辑，已生成排程保持快照不变
     */
    public function test_route_referenced_by_task_editable_and_schedule_unchanged(): void
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        $route = $product->getPublishedRoute();
        $this->assertNotNull($route);
        $machine = \App\Models\Machine::where('code', 'I-01')->firstOrFail();
        $oldOp = $route->ops()->first();
        $this->assertNotNull($oldOp);

        $order = Order::create([
            'order_no' => 'SO-EDT-'.uniqid(),
            'src' => Order::SRC_MANUAL,
            'customer_name' => '编辑生命周期测试客户',
            'status' => Order::STATUS_PENDING,
            'priority' => 3,
            'delivery_date' => now()->addDays(1),
        ]);
        $line = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1000,
            'delivery_date' => now()->addDays(1),
            'priority' => 3,
            'status' => OrderLine::STATUS_PENDING,
            'done_qty' => 0,
            'mold_id' => $product->default_mold_id,
            'line_no' => 1,
        ]);
        ProdTask::create([
            'task_no' => 'TSK-EDT-'.uniqid(),
            'order_line_id' => $line->id,
            'product_id' => $product->id,
            'route_id' => $route->id,
            'mold_id' => $product->default_mold_id,
            'qty' => 1000,
            'done_qty' => 0,
            'status' => ProdTask::STATUS_UNSCHEDULED,
            'priority' => 3,
            'delivery_date' => now()->addDays(1)->toDateString(),
            'deviation_pct' => 0,
            'plan_start' => null,
            'plan_end' => null,
            'is_locked' => 0,
            'source' => 'manual',
        ]);

        // 生成排程（快照：冗余 op_type/op_name/机台）
        \App\Models\TaskSchedule::create([
            'task_id' => \App\Models\ProdTask::where('route_id', $route->id)->firstOrFail()->id,
            'seq' => 1,
            'route_op_id' => $oldOp->id,
            'op_type' => $oldOp->op_type,
            'op_name' => $oldOp->op_name,
            'machine_id' => $machine->id,
            'mold_id' => $product->default_mold_id,
            'plan_start' => now()->addHours(1),
            'plan_end' => now()->addHours(3),
            'qty' => 1000,
            'status' => \App\Models\TaskSchedule::STATUS_PENDING,
            'source' => \App\Models\TaskSchedule::SOURCE_MANUAL,
        ]);

        // 被引用路线编辑成功（头部 + 工序）
        $response = $this->api($this->token)->putJson("/api/plm/routes/{$route->id}", [
            'name' => '被引用工艺改名',
            'version' => 'R2',
            'ops' => [
                ['seq' => 1, 'op_type' => 'molding_injection', 'op_name' => '注塑成型', 'workshop_id' => $machine->workshop_id, 'mold_change_min' => 60, 'shift_mode' => 'two_shift', 'machine_type' => 'injection', 'default_machine_id' => $machine->id],
            ],
        ]);
        $response->assertOk()->assertJsonPath('data.name', '被引用工艺改名');

        // 已生成排程保持快照：op_type/op_name/机台 均未随路线编辑变化
        $schedule = \App\Models\TaskSchedule::where('task_id', \App\Models\ProdTask::where('route_id', $route->id)->firstOrFail()->id)->firstOrFail();
        $this->assertSame($oldOp->op_type, $schedule->op_type);
        $this->assertSame($oldOp->op_name, $schedule->op_name);
        $this->assertSame($machine->id, (int) $schedule->machine_id);
    }

    /**
     * 未引用的草稿路线：名称/版本/工序均可修改
     */
    public function test_draft_route_edit_ops_and_header(): void
    {
        $machine = \App\Models\Machine::where('code', 'I-01')->firstOrFail();
        $created = $this->api($this->token)->postJson('/api/plm/routes', [
            'name' => '草稿可编辑工艺',
            'version' => 'R1',
            'ops' => [
                ['seq' => 1, 'op_type' => 'molding_injection', 'op_name' => '注塑成型', 'workshop_id' => $machine->workshop_id, 'mold_change_min' => 30, 'shift_mode' => 'two_shift', 'machine_type' => 'injection', 'default_machine_id' => $machine->id],
            ],
        ]);
        $created->assertStatus(201);
        $routeId = $created->json('data.id');

        $line = \App\Models\Machine::where('code', 'P-01')->firstOrFail();
        $response = $this->api($this->token)->putJson("/api/plm/routes/{$routeId}", [
            'name' => '草稿工艺改名',
            'version' => 'R2',
            'remark' => '更新说明',
            'ops' => [
                ['seq' => 1, 'op_type' => 'molding_injection', 'op_name' => '注塑成型', 'workshop_id' => $machine->workshop_id, 'mold_change_min' => 30, 'shift_mode' => 'two_shift', 'machine_type' => 'injection', 'default_machine_id' => $machine->id],
                ['seq' => 2, 'op_type' => 'paint', 'op_name' => '喷漆', 'workshop_id' => $line->workshop_id, 'shift_mode' => 'day', 'machine_type' => null, 'default_line_id' => $line->id],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', '草稿工艺改名')
            ->assertJsonPath('data.version', 'R2')
            ->assertJsonPath('data.remark', '更新说明')
            ->assertJsonCount(2, 'data.ops');
    }

    /**
     * 名称+版本唯一冲突
     */
    public function test_duplicate_name_version_rejected(): void
    {
        $a = Product::where('code', 'P-0001')->firstOrFail()->getPublishedRoute();
        $b = Product::where('code', 'P-0002')->firstOrFail()->getPublishedRoute();
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        $this->api($this->token)->putJson("/api/plm/routes/{$b->id}", [
            'name' => $a->name,
            'version' => $a->version,
        ])->assertStatus(422);
    }
}
