<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PlmRoute;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\WorkOrderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 工艺确认流程（订单创建 → 工艺确认 → 排产）
 *
 * 规则：
 * - 订单创建默认进入 route_confirm（工艺确认）
 * - 配置/调整任务工艺路线（PUT /orders/{id}/routes）；**仅 route_confirm 阶段可改，确认进入 pending 后锁定**（2026-09-01 用户要求）
 * - 确认（POST /orders/{id}/route-confirm）：全部任务工艺已配置且已发布 → pending
 * - 排产引擎 gate：route_confirm 订单的任务不可排产（409）
 */
class RouteConfirmTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /** 建单（P-0001 默认工艺 R1 已发布） */
    private function createOrder(): Order
    {
        $r = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '工艺确认测试客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 5000]],
        ])->assertStatus(201);

        return Order::find($r->json('data.id'));
    }

    private function firstTask(Order $order): ProdTask
    {
        return ProdTask::where('order_line_id', $order->lines()->value('id'))->firstOrFail();
    }

    // ==================== 状态机 ====================

    public function test_create_order_defaults_to_route_confirm(): void
    {
        $order = $this->createOrder();

        $this->assertSame(Order::STATUS_ROUTE_CONFIRM, $order->status);
    }

    public function test_confirm_flow_pending_then_schedulable(): void
    {
        $order = $this->createOrder();

        // 确认 → pending
        $this->api($this->token)->postJson("/api/orders/{$order->id}/route-confirm")
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);

        // 日志留痕
        $this->assertDatabaseHas('work_order_logs', [
            'order_id' => $order->id,
            'action' => WorkOrderLog::ACTION_ROUTE_CONFIRM,
            'from_status' => 'route_confirm',
            'to_status' => 'pending',
        ]);

        // 确认后排产成功
        $task = $this->firstTask($order);
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => 1, // I-01（M-001 适用）
                'plan_start' => now()->addDays(2)->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'scheduled']);
    }

    // ==================== 排产 gate ====================

    public function test_schedule_rejected_before_route_confirm(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);

        $r = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => 1,
                'plan_start' => now()->addDays(2)->format('Y-m-d H:i:s'),
            ]],
        ]);

        $r->assertStatus(409);
        $this->assertStringContainsString('工艺确认', $r->json('message'));
        $this->assertDatabaseCount('task_schedules', 0);
    }

    // ==================== 确认校验 ====================

    public function test_confirm_rejected_when_task_route_missing(): void
    {
        $order = $this->createOrder();
        $this->firstTask($order)->update(['route_id' => null]);

        $r = $this->api($this->token)->postJson("/api/orders/{$order->id}/route-confirm");

        $r->assertStatus(422);
        $this->assertStringContainsString('未配置工艺路线', $r->json('message'));
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'route_confirm']);
    }

    public function test_confirm_rejected_when_route_not_published(): void
    {
        $order = $this->createOrder();
        $draft = PlmRoute::create(['product_id' => $order->lines()->value('product_id'), 'name' => '测试工艺RX', 'version' => 'RX', 'status' => PlmRoute::STATUS_DRAFT]);
        $this->firstTask($order)->update(['route_id' => $draft->id]);

        $this->api($this->token)->postJson("/api/orders/{$order->id}/route-confirm")
            ->assertStatus(422)->assertJsonPath('message', function ($m) {
                return is_string($m) && str_contains($m, '未发布');
            });
    }

    // ==================== 工艺配置/调整 ====================

    public function test_update_task_route_then_reconfirm(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $oldRouteId = $task->route_id;

        // 换一条已发布路线（同产品建 R2）
        $r2 = PlmRoute::create(['product_id' => $order->lines()->value('product_id'), 'name' => '测试工艺R2', 'version' => 'R2', 'status' => PlmRoute::STATUS_PUBLISHED]);

        $this->api($this->token)->putJson("/api/orders/{$order->id}/routes", [
            'tasks' => [['task_id' => $task->id, 'route_id' => $r2->id]],
        ])->assertOk();

        $task->refresh();
        $this->assertSame($r2->id, (int) $task->route_id);
        $this->assertNotSame($oldRouteId, (int) $task->route_id);

        // 确认后重新确认（重确认幂等）
        $this->api($this->token)->postJson("/api/orders/{$order->id}/route-confirm")->assertOk();
    }

    public function test_multi_product_order_can_change_route_for_each_task(): void
    {
        $created = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '多产品换路线客户',
            'lines' => [
                ['product_code' => 'P-0001', 'qty' => 1000],
                ['product_code' => 'P-0003', 'qty' => 800],
            ],
        ])->assertStatus(201);
        $order = Order::with('lines')->findOrFail($created->json('data.id'));
        $tasks = ProdTask::whereIn('order_line_id', $order->lines->pluck('id'))->orderBy('id')->get();
        $targetRoute = PlmRoute::where('status', PlmRoute::STATUS_PUBLISHED)->orderByDesc('id')->firstOrFail();

        $this->api($this->token)->putJson("/api/orders/{$order->id}/routes", [
            'tasks' => $tasks->map(fn (ProdTask $task) => [
                'task_id' => $task->id,
                'route_id' => $targetRoute->id,
            ])->all(),
        ])->assertOk();

        $this->assertSame(
            [$targetRoute->id, $targetRoute->id],
            $tasks->map(fn (ProdTask $task) => (int) $task->fresh()->route_id)->all(),
            '多产品工单的每个任务都应能完成路线切换',
        );
    }

    public function test_update_route_rejected_after_scheduled(): void
    {
        $order = $this->createOrder();
        $this->api($this->token)->postJson("/api/orders/{$order->id}/route-confirm")->assertOk();
        $task = $this->firstTask($order);
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => 1, 'plan_start' => now()->addDays(2)->format('Y-m-d H:i:s')]],
        ])->assertOk();

        $r2 = PlmRoute::create(['product_id' => $order->lines()->value('product_id'), 'name' => '测试工艺R3', 'version' => 'R3', 'status' => PlmRoute::STATUS_PUBLISHED]);
        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/routes", [
            'tasks' => [['task_id' => $task->id, 'route_id' => $r2->id]],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('已确认锁定', $r->json('message'));
    }

    public function test_update_route_rejected_after_confirm_pending(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);

        // 确认 → pending：工艺路线锁定，不可再更换
        $this->api($this->token)->postJson("/api/orders/{$order->id}/route-confirm")->assertOk();

        $r2 = PlmRoute::create(['product_id' => $order->lines()->value('product_id'), 'name' => '测试工艺R3', 'version' => 'R3', 'status' => PlmRoute::STATUS_PUBLISHED]);
        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/routes", [
            'tasks' => [['task_id' => $task->id, 'route_id' => $r2->id]],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('已确认锁定', $r->json('message'));
        $task->refresh();
        $this->assertNotSame((int) $r2->id, (int) $task->route_id, '待排产阶段被拒后不得更换路线');
    }

    public function test_route_config_validates_published_only(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $draft = PlmRoute::create(['product_id' => $order->lines()->value('product_id'), 'name' => '测试工艺RX', 'version' => 'RX', 'status' => PlmRoute::STATUS_DRAFT]);

        $this->api($this->token)->putJson("/api/orders/{$order->id}/routes", [
            'tasks' => [['task_id' => $task->id, 'route_id' => $draft->id]],
        ])->assertStatus(422)->assertJsonPath('message', function ($m) {
            return is_string($m) && str_contains($m, '未发布');
        });
    }

    // ==================== 状态联动 ====================

    public function test_start_rejected_in_route_confirm(): void
    {
        $order = $this->createOrder();

        $this->api($this->token)->postJson("/api/orders/{$order->id}/start")
            ->assertStatus(409)->assertJsonPath('message', function ($m) {
                return is_string($m) && str_contains($m, '待排产');
            });
    }

    public function test_sync_status_keeps_route_confirm(): void
    {
        $order = $this->createOrder();

        // 报工/排程等联动触发 syncStatus 后不应把 route_confirm 覆盖为 pending
        app(\App\Modules\Order\Services\OrderService::class)->syncStatus($order->fresh());

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'route_confirm']);
    }
}
