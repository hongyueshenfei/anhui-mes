<?php

namespace Tests\Feature\Order;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProdTask;
use App\Models\RouteOp;
use App\Models\TaskSchedule;
use App\Models\WorkOrderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 工单中心生命周期测试（编辑/开始/报工/完结/撤销/日志 + 工序进度）
 *
 * - 开始/完结/撤销 状态流转 + 操作日志留痕
 * - 报工驱动工序进度（work_reports.schedule_id → task_schedules.op_type 聚合）
 * - 已入库工单不可再编辑
 * - 待排产工单不可直接撤销（需先开始/排产）
 */
class WorkOrderLifecycleTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    public function test_lifecycle_start_report_complete_edit_revoke_and_logs(): void
    {
        // 1) 建单
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '生命周期客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1000]],
        ]);
        $create->assertStatus(201);
        $id = $create->json('data.id');

        // 1.5) 工艺确认：订单创建默认 route_confirm，确认后进入待排产
        $this->api($this->token)->postJson("/api/orders/{$id}/route-confirm")->assertOk();

        // 2) 为生产任务建一条排程，使工序进度可计算
        $line = OrderLine::where('order_id', $id)->firstOrFail();
        $task = $line->tasks->first();
        $this->assertNotNull($task, '建单应自动生成生产任务');
        $taskId = $task->id;

        $schedule = TaskSchedule::create([
            'task_id' => $taskId,
            'seq' => 1,
            'route_op_id' => 1,
            'op_type' => RouteOp::OP_MOLDING_INJECTION,
            'plan_start' => now(),
            'plan_end' => now()->addHours(2),
            'qty' => 1000,
            'status' => 'running',
            'source' => 'manual',
        ]);
        $scheduleId = $schedule->id;

        // 任务需先排产方可报工（与线上流程一致：未排产任务不可报工）
        ProdTask::whereKey($taskId)->update(['status' => ProdTask::STATUS_SCHEDULED]);

        // 3) 开始生产
        $start = $this->api($this->token)->postJson("/api/orders/{$id}/start");
        $start->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_IN_PROGRESS);
        $this->assertDatabaseHas('work_order_logs', [
            'order_id' => $id,
            'action' => WorkOrderLog::ACTION_START,
        ]);

        // 4) 报工（带 schedule_id，驱动工序进度）
        $report = $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $taskId,
            'schedule_id' => $scheduleId,
            'qty' => 500,
            'source' => 'click',
        ]);
        $report->assertStatus(201);

        $wr = \Illuminate\Support\Facades\DB::table('work_reports')->orderByDesc('id')->first();
        $this->assertEquals($taskId, (int) $wr->task_id, 'work_report.task_id 应等于任务 id');
        $this->assertEquals($scheduleId, (int) $wr->schedule_id, 'work_report.schedule_id 应等于排程 id');

        $progress = $this->api($this->token)->getJson("/api/orders/{$id}/progress");
        $progress->assertOk();
        $pdata = $progress->json('data');

        $this->assertGreaterThanOrEqual(45, $pdata['percent']);
        $this->assertLessThanOrEqual(55, $pdata['percent']);
        $stage = collect($pdata['stages'])->firstWhere('op_type', RouteOp::OP_MOLDING_INJECTION);
        $this->assertNotNull($stage, '应包含注塑成型工序分段');
        $this->assertEquals(500, $stage['done_qty']);

        // 5) 编辑工单头信息（正常，工单仍生产中）
        $update = $this->api($this->token)->patchJson("/api/orders/{$id}", [
            'customer_name' => '改名客户',
            'priority' => 5,
        ]);
        $update->assertOk()
            ->assertJsonPath('data.customer_name', '改名客户')
            ->assertJsonPath('data.priority', 5);

        // 完结前必须补齐末工序/单工序剩余报工，不能由完结按钮伪造数量。
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $taskId,
            'schedule_id' => $scheduleId,
            'qty' => 500,
            'source' => 'click',
        ])->assertStatus(201);

        // 6) 完结入库
        $complete = $this->api($this->token)->postJson("/api/orders/{$id}/complete");
        $complete->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_PRODUCED);
        $this->assertNotNull($complete->json('data.completed_at'));
        $this->assertDatabaseHas('work_order_logs', [
            'order_id' => $id,
            'action' => WorkOrderLog::ACTION_COMPLETE,
        ]);

        // 7) 已入库后编辑应被拒（409）
        $blocked = $this->api($this->token)->patchJson("/api/orders/{$id}", ['remark' => 'x']);
        $blocked->assertStatus(409);

        // 8) 日志列表（含 start 动作，且 >= 2 条）
        $logs = $this->api($this->token)->getJson("/api/orders/{$id}/logs");
        $logs->assertOk();
        $logData = $logs->json('data.data');
        $this->assertGreaterThanOrEqual(2, count($logData));
        $actions = collect($logData)->pluck('action')->all();
        $this->assertContains(WorkOrderLog::ACTION_START, $actions);
    }

    public function test_complete_rejects_incomplete_tasks_without_faking_schedule_progress(): void
    {
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '禁止虚假完结客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1000]],
        ])->assertStatus(201);
        $id = $create->json('data.id');
        $this->api($this->token)->postJson("/api/orders/{$id}/route-confirm")->assertOk();

        $task = ProdTask::where('order_line_id', Order::findOrFail($id)->lines()->value('id'))->firstOrFail();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => 1,
                'plan_start' => now()->addDays(2)->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $response = $this->api($this->token)->postJson("/api/orders/{$id}/complete");

        $response->assertStatus(409);
        $this->assertStringContainsString('尚未完成', (string) $response->json('message'));
        $this->assertSame(Order::STATUS_SCHEDULED, Order::findOrFail($id)->status);
        $this->assertSame(ProdTask::STATUS_SCHEDULED, $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->done_qty);
        $this->assertDatabaseHas('task_schedules', [
            'task_id' => $task->id,
            'status' => TaskSchedule::STATUS_PENDING,
            'done_qty' => 0,
        ]);
    }

    public function test_revoke_from_pending_rejected_then_after_start_allowed(): void
    {
        // 新单：待排产
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '撤销客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 100]],
        ]);
        $newId = $create->json('data.id');

        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产
        $this->api($this->token)->postJson("/api/orders/{$newId}/route-confirm")->assertOk();

        // 待排产不可直接撤销
        $this->api($this->token)->postJson("/api/orders/{$newId}/revoke")->assertStatus(409);

        // 先开始，再撤销 → 回到待排产
        $this->api($this->token)->postJson("/api/orders/{$newId}/start")->assertOk();
        $revoke = $this->api($this->token)->postJson("/api/orders/{$newId}/revoke");
        $revoke->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_PENDING);
    }

    public function test_pause_resume_abort_close_lifecycle(): void
    {
        // 建单 → 工艺确认 → 开工
        $create = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '停工中止客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1000]],
        ]);
        $id = $create->json('data.id');
        $this->api($this->token)->postJson("/api/orders/{$id}/route-confirm")->assertOk();

        // 准备一条生产中任务排程，验证订单停工会同步反映到甘特图数据源。
        $line = OrderLine::where('order_id', $id)->firstOrFail();
        $task = $line->tasks->firstOrFail();
        TaskSchedule::create([
            'task_id' => $task->id,
            'seq' => 1,
            'route_op_id' => 1,
            'op_type' => RouteOp::OP_MOLDING_INJECTION,
            'plan_start' => now(),
            'plan_end' => now()->addHours(2),
            'qty' => 1000,
            'status' => TaskSchedule::STATUS_RUNNING,
            'source' => TaskSchedule::SOURCE_MANUAL,
        ]);
        $this->api($this->token)->postJson("/api/orders/{$id}/start")->assertOk();
        ProdTask::whereKey($task->id)->update(['status' => ProdTask::STATUS_IN_PROGRESS]);

        // 停工：in_progress → paused，任务暂停 + 日志
        $pause = $this->api($this->token)->postJson("/api/orders/{$id}/pause");
        $pause->assertOk()->assertJsonPath('data.status', Order::STATUS_PAUSED);
        $this->assertDatabaseHas('task_schedules', [
            'task_id' => $task->id,
            'status' => TaskSchedule::STATUS_PAUSED,
        ]);
        $this->assertDatabaseHas('work_order_logs', ['order_id' => $id, 'action' => WorkOrderLog::ACTION_PAUSE]);

        // 恢复生产：paused → in_progress（开工复用）
        $resume = $this->api($this->token)->postJson("/api/orders/{$id}/start");
        $resume->assertOk()->assertJsonPath('data.status', Order::STATUS_IN_PROGRESS);
        $this->assertDatabaseHas('task_schedules', [
            'task_id' => $task->id,
            'status' => TaskSchedule::STATUS_PENDING,
        ]);

        // 中止：in_progress → aborted
        $abort = $this->api($this->token)->postJson("/api/orders/{$id}/abort");
        $abort->assertOk()->assertJsonPath('data.status', Order::STATUS_ABORTED);
        $this->assertDatabaseHas('work_order_logs', ['order_id' => $id, 'action' => WorkOrderLog::ACTION_ABORT]);

        // 已中止不可重复关闭
        $this->api($this->token)->postJson("/api/orders/{$id}/close")->assertStatus(409);

        // 待排产订单可关闭（任意非终态）
        $create2 = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '关闭客户',
            'lines' => [['product_code' => 'P-0002', 'qty' => 500]],
        ]);
        $id2 = $create2->json('data.id');
        $close = $this->api($this->token)->postJson("/api/orders/{$id2}/close");
        $close->assertOk()->assertJsonPath('data.status', Order::STATUS_CLOSED);
        $this->assertDatabaseHas('work_order_logs', ['order_id' => $id2, 'action' => WorkOrderLog::ACTION_CLOSE]);

        // 工艺确认中订单不可中止
        $create3 = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '中止拒绝客户',
            'lines' => [['product_code' => 'P-0003', 'qty' => 300]],
        ]);
        $id3 = $create3->json('data.id');
        $this->api($this->token)->postJson("/api/orders/{$id3}/abort")->assertStatus(409);
    }
}
