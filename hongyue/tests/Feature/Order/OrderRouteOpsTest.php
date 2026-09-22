<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\ProdTask;
use App\Models\RouteOp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 订单级工艺明细（GET/PUT /orders/{id}/route-ops）
 *
 * 规则：
 * - 仅 route_confirm / pending 状态可编辑，排产后锁定（422）
 * - 工序快照写入 prod_tasks.route_ops_override，只对当前工单生效
 * - 不回写工艺路线库（route_ops 不变化）
 * - 逐工序校验：op_type/op_name/workshop_id 必填；设备可选（排产阶段选择），
 *   若指定则校验类型与车间归属；数量 qty 可空（默认任务数量），指定须 >= 1
 * - 落库 seq 按提交顺序强制 1..n
 */
class OrderRouteOpsTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /** 建单（P-0001 默认工艺 R1 已发布，4 道工序） */
    private function createOrder(): Order
    {
        $r = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '工艺明细测试客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 5000]],
        ])->assertStatus(201);

        return Order::find($r->json('data.id'));
    }

    private function firstTask(Order $order): ProdTask
    {
        return ProdTask::where('order_line_id', $order->lines()->value('id'))->firstOrFail();
    }

    /** GET route-ops 取任务生效工序（提交到 PUT 的原始形状） */
    private function fetchOps(Order $order, int $taskId): array
    {
        $r = $this->api($this->token)->getJson("/api/orders/{$order->id}/route-ops")->assertOk();

        return collect($r->json('data.tasks'))->firstWhere('task_id', $taskId)['ops'];
    }

    /**
     * 可提交化：seed 的默认工艺注塑工序未配默认机台（工艺库允许可选），
     * 订单级编辑要求成型工序必选机台 → 补选 I-01 注塑机
     */
    private function prepareOps(array $ops): array
    {
        return array_map(function ($op) {
            if (($op['op_type'] ?? null) === 'molding_injection' && empty($op['machine_id'])) {
                $op['machine_id'] = 1; // I-01（injection 车间）
            }

            return $op;
        }, $ops);
    }

    /** 工艺确认 + 排产 → scheduled */
    private function scheduleOrder(Order $order): void
    {
        $this->api($this->token)->postJson("/api/orders/{$order->id}/route-confirm")->assertOk();
        $task = $this->firstTask($order);
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => 1, 'plan_start' => now()->addDays(2)->format('Y-m-d H:i:s')]],
        ])->assertOk();
    }

    // ==================== 状态 gate ====================

    public function test_route_ops_editable_in_route_confirm_rejected_after_confirm(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));

        // route_confirm 可编辑
        $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ])->assertOk();

        // 确认 → pending（工艺锁定）：编辑工艺明细被拒，且不得落库
        $this->api($this->token)->postJson("/api/orders/{$order->id}/route-confirm")->assertOk();
        $task->refresh();
        $this->assertTrue($task->hasRouteOpsOverride(), 'route_confirm 阶段已保存的定制应保留');

        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $this->prepareOps([['seq' => 1, 'op_type' => 'paint', 'op_name' => '喷漆', 'workshop_id' => 3]])]],
        ]);
        $r->assertStatus(422);
        $this->assertStringContainsString('已确认锁定', $r->json('message'));
    }

    public function test_route_ops_rejected_after_scheduled(): void
    {
        $order = $this->createOrder();
        $this->scheduleOrder($order);
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));

        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('已确认锁定', $r->json('message'));
        $task->refresh();
        $this->assertFalse($task->hasRouteOpsOverride(), '被拒后不得落库');
    }

    // ==================== 编辑落库 + 返回值 ====================

    public function test_override_persists_and_returns_modified_values(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));

        $this->assertCount(4, $ops, 'P-0001 默认工艺 4 道工序');
        $this->assertFalse($task->hasRouteOpsOverride(), '未编辑前无快照');

        $owner = User::create(['employee_no' => 'E-OWN-001', 'name' => '工序负责人甲', 'job_type' => 'worker', 'status' => 1]);

        // 提交 3 道：第 1 道注塑（改工时/负责人/备注），第 2/3 道（喷漆/效果）删除，末尾新增一道包装
        $molding = $ops[0];
        $pack = $ops[3];
        $submit = [
            ['op_type' => $molding['op_type'], 'op_name' => $molding['op_name'], 'workshop_id' => $molding['workshop_id'], 'machine_id' => $molding['machine_id'], 'std_hours' => 2.5, 'owner_id' => $owner->id, 'remark' => '首件确认'],
            ['op_type' => $pack['op_type'], 'op_name' => $pack['op_name'], 'workshop_id' => $pack['workshop_id'], 'line_id' => $pack['line_id']],
            ['op_type' => $pack['op_type'], 'op_name' => '抽检包装', 'workshop_id' => $pack['workshop_id'], 'line_id' => $pack['line_id']],
        ];

        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $submit]],
        ])->assertOk();

        // 落库断言
        $task->refresh();
        $this->assertTrue($task->hasRouteOpsOverride());
        $saved = $task->route_ops_override;
        $this->assertCount(3, $saved);
        $this->assertSame([1, 2, 3], array_column($saved, 'seq'), '落库 seq 应为 1..n');
        $this->assertEquals(2.5, $saved[0]['std_hours'], '改工时应落库');
        $this->assertSame($owner->id, $saved[0]['owner_id'], '改负责人应落库');
        $this->assertSame('首件确认', $saved[0]['remark']);
        $this->assertSame('抽检包装', $saved[2]['op_name'], '新增工序应落库');

        // 返回值断言
        $data = $r->json('data');
        $this->assertSame($order->id, $data['order_id']);
        $this->assertTrue($data['editable']);
        $row = collect($data['tasks'])->firstWhere('task_id', $task->id);
        $this->assertTrue($row['overridden']);
        $respOps = $row['ops'];
        $this->assertCount(3, $respOps);
        $this->assertSame(2.5, $respOps[0]['std_hours']);
        $this->assertSame('工序负责人甲', $respOps[0]['owner_name'], '负责人名应反查');
        $this->assertSame('抽检包装', $respOps[2]['op_name']);
        $this->assertSame([1, 2, 3], array_column($respOps, 'seq'));
        // machine_id / line_id 互斥：注塑有机台无产线，包装有产线无机台
        $this->assertNotNull($respOps[0]['machine_id']);
        $this->assertNull($respOps[0]['line_id']);
        $this->assertNull($respOps[2]['machine_id']);
        $this->assertNotNull($respOps[2]['line_id']);

        // 删除的喷漆/效果不再出现
        $types = array_column($respOps, 'op_type');
        $this->assertNotContains('paint', $types);
        $this->assertNotContains('effect', $types);

        // 工单日志留痕
        $this->assertDatabaseHas('work_order_logs', [
            'order_id' => $order->id,
            'action' => 'edit',
            'remark' => "编辑工序明细：{$task->task_no} 由 4 道调整为 3 道",
        ]);
    }

    // ==================== 不回写工艺路线库 ====================

    public function test_route_library_not_polluted(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $routeId = $task->route_id;

        $before = RouteOp::where('route_id', $routeId)->orderBy('seq')
            ->get(['seq', 'op_type', 'op_name', 'std_hours', 'owner_id', 'remark'])
            ->toArray();
        $this->assertCount(4, $before, '工艺路线库初始 4 道');

        // 编辑：改工时 + 删一道
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));
        $ops[0]['std_hours'] = 9.9;
        $ops = array_slice($ops, 0, 3);
        $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ])->assertOk();

        $after = RouteOp::where('route_id', $routeId)->orderBy('seq')
            ->get(['seq', 'op_type', 'op_name', 'std_hours', 'owner_id', 'remark'])
            ->toArray();
        $this->assertSame($before, $after, '订单级编辑不得污染工艺路线库');
    }

    // ==================== 逐工序校验 ====================

    public function test_validation_op_name_required(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));
        $ops[0]['op_name'] = '   ';

        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('工序名称', $r->json('message'));
    }

    public function test_validation_molding_machine_optional(): void
    {
        // 业务规则：工艺确认阶段不选设备（排产时才指定），设备留空允许保存
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->fetchOps($order, $task->id);
        foreach ($ops as $i => $_op) {
            $ops[$i]['machine_id'] = null;
            $ops[$i]['line_id'] = null;
        }

        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ]);

        $r->assertOk();
        $task->refresh();
        $this->assertNotNull($task->route_ops_override, '设备留空的工序明细应可保存');
        $this->assertNull($task->route_ops_override[0]['machine_id'] ?? null, '注塑工序设备保持为空');
    }

    public function test_validation_molding_wrong_machine_type_rejected(): void
    {
        // 若工艺阶段已指定设备，则类型必须匹配（注塑工序不能选吹塑机）
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));
        $ops[0]['machine_id'] = 6; // 吹塑机 B-02（type=blow）

        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('机台类型不匹配', $r->json('message'));
    }

    public function test_validation_workshop_must_exist(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));
        $ops[1]['workshop_id'] = 99999;

        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('车间', $r->json('message'));
    }

    public function test_op_qty_persisted_and_defaults_to_task_qty(): void
    {
        // 工序数量：指定则存储，未指定默认任务数量；作为排产/报工的数量依据
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));
        $ops[0]['qty'] = 800; // 首道注塑 800，其余工序不带 qty

        $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ])->assertOk();

        $r = $this->api($this->token)->getJson("/api/orders/{$order->id}/route-ops")->assertOk();
        $saved = collect($r->json('data.tasks'))->firstWhere('task_id', $task->id)['ops'];
        $this->assertSame(800, $saved[0]['qty'], '首道工序数量保存为 800');
        $this->assertSame(5000, $saved[1]['qty'], '未指定 qty 的工序默认任务数量 5000');

        $task->refresh();
        $this->assertSame(800, $task->route_ops_override[0]['qty'], 'override 落库含 qty');
    }

    public function test_op_qty_invalid_rejected(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));
        $ops[0]['qty'] = 0;

        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('数量', $r->json('message'));
    }

    public function test_route_ops_includes_schedule_readonly_fields(): void
    {
        // 排程后：工序的排产开始/结束/机台产线由 route-ops 自动带出（只读）
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));
        $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ])->assertOk();

        // 排程前：排产/完工字段为空态
        $before = collect($this->fetchOps($order, $task->id));
        $this->assertNull($before[0]['plan_start']);
        $this->assertNull($before[0]['machines']);

        $this->scheduleOrder($order);

        $after = collect($this->fetchOps($order, $task->id));
        $this->assertNotNull($after[0]['plan_start'], '排程后带排产开始时间');
        $this->assertNotNull($after[0]['plan_end'], '排程后带排产结束时间');
        $this->assertNotEmpty($after[0]['machines'], '排程后带机台/产线');
        $this->assertSame(0, (int) $after[0]['done_qty'], '未报工完工数量为 0');
        $this->assertSame(0.0, (float) $after[0]['percent'], '未报工完工进度为 0');
    }

    public function test_schedule_chain_uses_op_qty_as_reporting_basis(): void
    {
        // 闭环：订单工序数量 → 排程各段数量 → 报工数量依据
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));
        $ops[0]['qty'] = 800;

        $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ])->assertOk();

        $this->scheduleOrder($order);

        $task->refresh();
        $schedules = $task->schedules()->orderBy('seq')->get();
        $this->assertCount(4, $schedules, '可排产：确认工艺后按路线拆 4 段');
        $this->assertSame(800, (int) $schedules[0]->qty, '首道（注塑）排程数量 = 订单工序数量 800');
        $this->assertSame((int) $task->qty, (int) $schedules[1]->qty, '其余工序排程数量默认任务数量');
        $this->assertSame(0, (int) $schedules[0]->done_qty, '报工未开始');
    }

    public function test_schedule_chain_uses_order_level_operation_snapshot(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));

        // 工单只保留成型、包装两道工序；排产链必须跟随工单快照，而不是重新读取标准四道工艺。
        $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => [$ops[0], $ops[3]]]],
        ])->assertOk();

        $this->scheduleOrder($order);

        $schedules = $task->fresh()->schedules()->orderBy('seq')->get();
        $this->assertCount(2, $schedules);
        $this->assertSame(['molding_injection', 'pack'], $schedules->pluck('op_type')->all());
        $this->assertSame(['注塑成型', '包装'], $schedules->pluck('op_name')->all());
    }

    public function test_validation_op_type_invalid(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));
        $ops[0]['op_type'] = 'hacking';

        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $ops]],
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('工序类型', $r->json('message'));
    }

    // ==================== seq 连续性 ====================

    public function test_seq_reordered_continuously(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));

        // 乱序提交（倒序 + 伪造 seq）
        $reversed = array_map(fn ($op) => $op + ['seq' => 99], array_reverse($ops));

        $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => $reversed]],
        ])->assertOk();

        $task->refresh();
        $this->assertSame([1, 2, 3, 4], array_column($task->route_ops_override, 'seq'), '落库 seq 应按提交顺序重排为 1..n');
    }

    // ==================== 恢复标准工艺路线 ====================

    public function test_restore_standard_clears_override_and_falls_back_to_route_library(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);
        $routeId = $task->route_id;

        // 先定制：改工时 + 删 2 道（4 → 2）
        $ops = $this->prepareOps($this->fetchOps($order, $task->id));
        $molding = $ops[0];
        $pack = $ops[3];
        $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => [
                ['op_type' => $molding['op_type'], 'op_name' => $molding['op_name'], 'workshop_id' => $molding['workshop_id'], 'machine_id' => $molding['machine_id'], 'std_hours' => 2.5],
                ['op_type' => $pack['op_type'], 'op_name' => '抽检包装', 'workshop_id' => $pack['workshop_id'], 'line_id' => $pack['line_id']],
            ]]],
        ])->assertOk();

        $task->refresh();
        $this->assertTrue($task->hasRouteOpsOverride(), '定制后应有快照');

        // 恢复标准路线（前端语义：空 ops + restore_standard=true，可同时带 route_id）
        $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'route_id' => $routeId, 'restore_standard' => true, 'ops' => []]],
        ])->assertOk();

        // 1. 快照清空
        $task->refresh();
        $this->assertNull($task->route_ops_override, 'restore_standard 应清空订单级快照');
        $this->assertFalse($task->hasRouteOpsOverride());

        // 2. 再次 GET：overridden=false
        $data = $this->api($this->token)->getJson("/api/orders/{$order->id}/route-ops")->assertOk()->json('data');
        $row = collect($data['tasks'])->firstWhere('task_id', $task->id);
        $this->assertFalse($row['overridden'], '恢复后 overridden 应为 false');

        // 3. 工序数量与工艺路线库一致
        $libCount = \App\Models\PlmRoute::find($routeId)->ops()->count();
        $this->assertSame($libCount, count($row['ops']), '恢复后工序应与工艺路线库一致');
        $this->assertSame([1, 2, 3, 4], array_column($row['ops'], 'seq'));

        // 日志留痕
        $this->assertDatabaseHas('work_order_logs', [
            'order_id' => $order->id,
            'action' => 'edit',
            'remark' => "恢复标准工艺路线：{$task->task_no}（清除 2 道工序定制）",
        ]);
    }

    public function test_empty_ops_without_restore_standard_still_rejected(): void
    {
        $order = $this->createOrder();
        $task = $this->firstTask($order);

        $r = $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => [['task_id' => $task->id, 'ops' => []]],
        ]);

        $r->assertStatus(422);
        $task->refresh();
        $this->assertFalse($task->hasRouteOpsOverride(), '全删工序保存仍应被拒且不落库');
    }

    public function test_multi_product_order_saves_route_ops_for_each_task(): void
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '多产品工序快照测试客户',
            'lines' => [
                ['product_code' => 'P-0001', 'qty' => 5000],
                ['product_code' => 'P-0003', 'qty' => 3000],
            ],
        ])->assertStatus(201);
        $order = Order::findOrFail($response->json('data.id'));
        $tasks = ProdTask::whereIn('order_line_id', $order->lines()->pluck('id'))
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $tasks, '测试订单应生成两个产品任务');

        $payload = $tasks->map(fn (ProdTask $task) => [
            'task_id' => $task->id,
            'ops' => $this->prepareOps($this->fetchOps($order, $task->id)),
        ])->all();

        $this->api($this->token)->putJson("/api/orders/{$order->id}/route-ops", [
            'tasks' => $payload,
        ])->assertOk();

        foreach ($tasks as $task) {
            $this->assertTrue(
                $task->fresh()->hasRouteOpsOverride(),
                "多产品订单任务 {$task->id} 的工序快照应独立保存",
            );
        }
    }
}
