<?php

namespace Tests\Feature\Scheduling;

use App\Models\Machine;
use App\Models\Mold;
use App\Models\MoldChangeMatrix;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\Product;
use App\Models\RouteOp;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * APS一期硬约束测试（第2步：吨位校验 + 换模矩阵）
 *
 * - 吨位不匹配拒绝（tonnage_mismatch）/ 任一侧为空向后兼容
 * - 换模矩阵命中 → changeover_minutes 采用矩阵值并计入机台占用
 * - 换模矩阵无记录 → 回退工序/机台默认
 * - 禁止切换（is_forbidden）拒绝（changeover_forbidden）
 */
class ApsEnhancementTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function createTask(string $productCode = 'P-0001', int $qty = 400): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => 'APS硬约束测试',
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        $orderId = $response->json('data.id');
        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产
        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);
    }

    private function saveSchedule(ProdTask $task, string $machineCode, string $planStart)
    {
        $machine = Machine::where('code', $machineCode)->first();

        return $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => $planStart]],
        ]);
    }

    private function moldingRow(ProdTask $task): TaskSchedule
    {
        return TaskSchedule::where('task_id', $task->id)
            ->whereIn('op_type', [RouteOp::OP_MOLDING_INJECTION, RouteOp::OP_MOLDING_BLOW])
            ->firstOrFail();
    }

    public function test_tonnage_mismatch_rejected(): void
    {
        // P-0001 所需 999 吨 > I-01 锁模力 280 吨 → 拒绝
        Product::where('code', 'P-0001')->update(['required_tonnage' => 999]);
        $task = $this->createTask('P-0001');

        $r = $this->saveSchedule($task, 'I-01', '2025-08-01 08:00:00');

        $r->assertStatus(409);
        $codes = collect($r->json('data.errors'))->pluck('code');
        $this->assertContains('tonnage_mismatch', $codes);
        $this->assertDatabaseCount('task_schedules', 0);
    }

    public function test_tonnage_null_backward_compatible(): void
    {
        // 任一侧为空 → 跳过校验（存量数据向后兼容）
        Product::where('code', 'P-0001')->update(['required_tonnage' => null]);
        Machine::where('code', 'I-01')->update(['tonnage' => null]);
        $task = $this->createTask('P-0001');

        $this->saveSchedule($task, 'I-01', '2025-08-01 08:00:00')->assertOk();
        $this->assertDatabaseCount('task_schedules', 4); // 4 工序链
    }

    public function test_changeover_matrix_hit_occupies_machine_time(): void
    {
        // M-001 → M-003 换模 90 分钟
        MoldChangeMatrix::create([
            'from_mold_id' => Mold::where('code', 'M-001')->first()->id,
            'to_mold_id' => Mold::where('code', 'M-003')->first()->id,
            'changeover_minutes' => 90,
        ]);

        // 前序任务（默认模具 M-001）占 I-01
        $taskA = $this->createTask('P-0001');
        $this->saveSchedule($taskA, 'I-01', '2025-08-01 08:00:00')->assertOk();

        // 后序任务 P-0003（默认模具 M-003）排同机台 → 换模取矩阵 90 分钟
        $taskB = $this->createTask('P-0003');
        $this->saveSchedule($taskB, 'I-01', '2025-08-03 08:00:00')->assertOk();

        $row = $this->moldingRow($taskB);
        $this->assertSame(90, (int) $row->changeover_minutes);
        // 400 件 @400/时 + 矩阵换模 90 分，再增加 5% 预留 = 2.625 时 → 08:00-10:37:30
        $this->assertSame('2025-08-03 10:37:30', $row->plan_end->format('Y-m-d H:i:s'));
    }

    public function test_batch_schedule_uses_previous_batch_mold_for_changeover_metadata(): void
    {
        MoldChangeMatrix::create([
            'from_mold_id' => Mold::where('code', 'M-001')->first()->id,
            'to_mold_id' => Mold::where('code', 'M-003')->first()->id,
            'changeover_minutes' => 90,
        ]);

        $taskA = $this->createTask('P-0001');
        $taskB = $this->createTask('P-0003');
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [
                ['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00'],
                ['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 12:00:00'],
            ],
        ])->assertOk();

        $this->assertSame(
            90,
            (int) $this->moldingRow($taskB)->changeover_minutes,
            '批量排产中后一个任务应读取本次请求已构建任务的前序模具'
        );
    }

    public function test_completed_schedule_forecast_does_not_block_previous_mold_resolution(): void
    {
        MoldChangeMatrix::create([
            'from_mold_id' => Mold::where('code', 'M-001')->first()->id,
            'to_mold_id' => Mold::where('code', 'M-003')->first()->id,
            'changeover_minutes' => 90,
        ]);

        $taskA = $this->createTask('P-0001');
        $this->saveSchedule($taskA, 'I-01', '2025-08-01 08:00:00')->assertOk();
        $this->moldingRow($taskA)->update([
            'status' => TaskSchedule::STATUS_DONE,
            'actual_end' => '2025-08-01 09:00:00',
            'forecast_end' => '2025-08-10 00:00:00',
        ]);

        $taskB = $this->createTask('P-0003');
        $this->saveSchedule($taskB, 'I-01', '2025-08-03 08:00:00')->assertOk();

        $this->assertSame(
            90,
            (int) $this->moldingRow($taskB)->changeover_minutes,
            '已完工行的晚预测时间不能覆盖实际/计划结束后的前序模具'
        );
    }

    public function test_changeover_fallback_without_matrix(): void
    {
        // 无矩阵记录 → 回退工序默认 30 分钟（与既有行为一致）
        $taskA = $this->createTask('P-0001');
        $this->saveSchedule($taskA, 'I-01', '2025-08-01 08:00:00')->assertOk();

        $taskB = $this->createTask('P-0003');
        $this->saveSchedule($taskB, 'I-01', '2025-08-03 08:00:00')->assertOk();

        $row = $this->moldingRow($taskB);
        $this->assertSame(30, (int) $row->changeover_minutes);
        // 400 件 @400/时 + 固定换模 30 分，再增加 5% 预留 = 1.575 时 → 08:00-09:34:30
        $this->assertSame('2025-08-03 09:34:30', $row->plan_end->format('Y-m-d H:i:s'));
    }

    public function test_forbidden_changeover_rejected(): void
    {
        // M-001 → M-005 禁止切换（如黑料→白料需清机）
        MoldChangeMatrix::create([
            'from_mold_id' => Mold::where('code', 'M-001')->first()->id,
            'to_mold_id' => Mold::where('code', 'M-005')->first()->id,
            'changeover_minutes' => 0,
            'is_forbidden' => 1,
            'remark' => '黑料→白料需清机，禁止直排',
        ]);
        Product::where('code', 'P-0005')->update(['status' => Product::STATUS_MASS]);

        $taskA = $this->createTask('P-0001');
        $this->saveSchedule($taskA, 'I-01', '2025-08-01 08:00:00')->assertOk();

        // P-0005 默认模具 M-005（适用 I-01~I-03）→ 被禁止切换拦截
        $taskC = $this->createTask('P-0005');
        $r = $this->saveSchedule($taskC, 'I-01', '2025-08-03 08:00:00');

        $r->assertStatus(409);
        $codes = collect($r->json('data.errors'))->pluck('code');
        $this->assertContains('changeover_forbidden', $codes);
        // 仅任务 A 的 4 行排程存在，任务 C 未落库
        $this->assertDatabaseMissing('task_schedules', ['task_id' => $taskC->id]);
    }

    // ==================== 倒排（APS一期第3步） ====================

    private function saveBackward(ProdTask $task, string $machineCode, string $planStart, ?string $deadline = null)
    {
        $machine = Machine::where('code', $machineCode)->first();

        return $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'mode' => 'backward',
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $planStart,
            ] + ($deadline !== null ? ['deadline' => $deadline] : [])],
        ]);
    }

    public function test_backward_schedules_from_deadline(): void
    {
        // 冻结时间：保证倒排起点晚于 now（否则必降级）
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));

        $task = $this->createTask('P-0001', 400);
        $task->update(['delivery_date' => '2025-08-10']);

        $r = $this->saveBackward($task, 'I-01', '2025-08-05 12:00:00');
        $r->assertOk();
        $this->assertSame([], $r->json('data.backward_downgraded'));

        // 交期 08-10 末逐工序倒推；产线按当前产能 P-01=1500、P-02=1500、P-03=3000 计算，
        // 各工序合计时长增加 5% 预留，成型固定换模 30 分钟。
        $molding = $this->moldingRow($task);
        $this->assertSame('2025-08-10 14:43:30', $molding->plan_start->format('Y-m-d H:i:s'));

        $pack = TaskSchedule::where('task_id', $task->id)->where('op_type', RouteOp::OP_PACK)->firstOrFail();
        $this->assertSame('2025-08-10 17:00:00', $pack->plan_end->format('Y-m-d H:i:s'));
    }

    public function test_backward_infeasible_falls_back_to_forward(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));

        $task = $this->createTask('P-0001', 400);
        // 交期在昨天 → 倒推起点必早于 now → 降级顺排（沿用请求给定起点）
        $task->update(['delivery_date' => '2025-08-04']);

        $r = $this->saveBackward($task, 'I-01', '2025-08-06 08:00:00');
        $r->assertOk();
        $this->assertSame([$task->task_no], $r->json('data.backward_downgraded'));
        $this->assertStringContainsString('交期倒推不可行', $r->json('message'));

        $molding = $this->moldingRow($task);
        $this->assertSame('2025-08-06 08:00:00', $molding->plan_start->format('Y-m-d H:i:s'));
    }

    public function test_backward_without_deadline_rejected(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));

        // 无任务交期且未传 deadline → 明确报错
        $task = $this->createTask('P-0001', 400);
        $this->assertNull($task->delivery_date);

        $r = $this->saveBackward($task, 'I-01', '2025-08-06 08:00:00');
        $r->assertStatus(409);
        $codes = collect($r->json('data.errors'))->pluck('code');
        $this->assertContains('no_deadline_for_backward', $codes);
    }

    // ==================== forecast 滚动 + 冻结区（APS一期第4步） ====================

    public function test_forecast_initialized_and_freeze_marked_on_save(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));

        // 冻结窗口内（08-06 ≤ 08-05 + 3天）
        $taskA = $this->createTask('P-0001', 400);
        $this->saveSchedule($taskA, 'I-01', '2025-08-06 08:00:00')->assertOk();

        // 冻结窗口外（08-10 > 08-08）
        $taskB = $this->createTask('P-0003', 400);
        $this->saveSchedule($taskB, 'I-01', '2025-08-10 08:00:00')->assertOk();

        $rowA = $this->moldingRow($taskA);
        $this->assertSame('2025-08-06 08:00:00', $rowA->forecast_start->format('Y-m-d H:i:s'));
        $this->assertSame($rowA->plan_end->format('Y-m-d H:i:s'), $rowA->forecast_end->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) $rowA->is_frozen);

        $rowB = $this->moldingRow($taskB);
        $this->assertSame(0, (int) $rowB->is_frozen);
    }

    public function test_manual_reschedule_recomputes_stale_freeze_mark(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));

        $task = $this->createTask('P-0001', 400);
        $this->saveSchedule($task, 'I-01', '2025-08-06 08:00:00')->assertOk();
        $this->assertSame(1, (int) $this->moldingRow($task)->is_frozen);

        // 保存即锁定是业务规则；模拟计划员先解锁再把任务移出冻结窗口。
        $task->fresh()->update(['is_locked' => 0]);
        $this->saveSchedule($task, 'I-01', '2025-08-12 08:00:00')->assertOk();

        $this->assertSame(0, (int) $this->moldingRow($task)->fresh()->is_frozen);
    }

    public function test_report_delay_rolls_forecast_without_touching_plan(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));

        $task = $this->createTask('P-0001', 400);
        $this->saveSchedule($task, 'I-01', '2025-08-09 08:00:00')->assertOk(); // 冻结窗口外
        $molding = $this->moldingRow($task);
        $paint = TaskSchedule::where('task_id', $task->id)->where('op_type', RouteOp::OP_PAINT)->firstOrFail();

        // 滞后报工：12:00 才报 100 件（成型原计划 08:00-09:30）
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-09 12:00:00'));
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 100, 'schedule_id' => $molding->id,
        ])->assertStatus(201);

        $molding->refresh();
        $paint->refresh();
        // actual 回填（首笔正报工时刻）
        $this->assertSame('2025-08-09 12:00:00', $molding->actual_start->format('Y-m-d H:i:s'));
        // 剩余 300 件 @400/时，增加 5% 预留 → forecast_end = 12:47:15；plan 不动
        $this->assertSame('2025-08-09 12:47:15', $molding->forecast_end->format('Y-m-d H:i:s'));
        $this->assertSame('2025-08-09 09:34:30', $molding->plan_end->format('Y-m-d H:i:s'));
        // 下游喷漆 forecast 传递：12:47:15 +（400/1500）× 1.05 = 13:04:03；plan 不动
        $this->assertSame('2025-08-09 13:04:03', $paint->forecast_end->format('Y-m-d H:i:s'));
        $this->assertSame('2025-08-09 09:51:18', $paint->plan_end->format('Y-m-d H:i:s'));
    }

    public function test_frozen_downstream_blocks_forecast_propagation(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));

        $task = $this->createTask('P-0001', 400);
        $this->saveSchedule($task, 'I-01', '2025-08-06 08:00:00')->assertOk(); // 全部落在冻结窗口内
        $molding = $this->moldingRow($task);
        $paint = TaskSchedule::where('task_id', $task->id)->where('op_type', RouteOp::OP_PAINT)->firstOrFail();
        $this->assertSame(1, (int) $paint->is_frozen);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-06 12:00:00'));
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 100, 'schedule_id' => $molding->id,
        ])->assertStatus(201);

        $molding->refresh();
        $paint->refresh();
        // 触发报工的工序更新自身 forecast（执行实况，冻结不拦）
        $this->assertSame('2025-08-06 12:47:15', $molding->forecast_end->format('Y-m-d H:i:s'));
        // 下游冻结工序：forecast 保持计划基线，传递停止
        $this->assertSame('2025-08-06 09:51:18', $paint->forecast_end->format('Y-m-d H:i:s'));
    }

    // ==================== 顺延建议层（APS一期第5步） ====================

    /** 构造「报工滞后破交期」场景，返回任务与成型排程 */
    private function setupDelayedScenario(string $deliveryDate): array
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));

        $task = $this->createTask('P-0001', 400);
        $task->update(['delivery_date' => $deliveryDate]);
        $this->saveSchedule($task, 'I-01', '2025-08-09 08:00:00')->assertOk(); // 冻结窗口外

        // 滞后报工：12:00 才报 100 件
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-09 12:00:00'));
        $molding = $this->moldingRow($task);
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 100, 'schedule_id' => $molding->id,
        ])->assertStatus(201);

        return [$task, $molding];
    }

    public function test_suggestion_generated_when_forecast_breaks_deadline(): void
    {
        [$task] = $this->setupDelayedScenario('2025-08-08'); // 链尾预计 08-09 > 交期 08-08

        $this->assertDatabaseCount('schedule_suggestions', 1);
        $suggestion = \App\Models\ScheduleSuggestion::first();
        $this->assertSame('pending', $suggestion->status);
        $this->assertSame($task->id, (int) $suggestion->task_id);
        $this->assertStringContainsString('突破交期', $suggestion->reason);
        $this->assertNotEmpty($suggestion->affected);
        $this->assertNotEmpty($suggestion->delayed_orders[0]['order_no']);
    }

    public function test_accept_suggestion_aligns_plan_to_forecast(): void
    {
        [$task, $molding] = $this->setupDelayedScenario('2025-08-08');

        $molding->refresh();
        $rolledForecastEnd = $molding->forecast_end->format('Y-m-d H:i:s');
        $this->assertSame('2025-08-09 12:47:15', $rolledForecastEnd);
        $versionsBefore = \App\Models\ScheduleVersion::count();

        $suggestion = \App\Models\ScheduleSuggestion::first();
        $r = $this->api($this->token)->postJson("/api/schedule/suggestions/{$suggestion->id}/accept");
        $r->assertOk();

        // 未开工的后续工序计划对齐预计；当前已开工成型工序保留计划基线。
        $molding->refresh();
        $this->assertSame('2025-08-09 09:34:30', $molding->plan_end->format('Y-m-d H:i:s'));
        $paint = $task->fresh()->schedules()->where('op_type', RouteOp::OP_PAINT)->firstOrFail();
        $this->assertSame('2025-08-09 13:04:03', $paint->plan_end->format('Y-m-d H:i:s'));
        $this->assertSame($versionsBefore + 1, \App\Models\ScheduleVersion::count());
        $this->assertSame('accepted', $suggestion->fresh()->status);

        // 重复采纳被拒（建议已处理）
        $this->api($this->token)->postJson("/api/schedule/suggestions/{$suggestion->id}/accept")->assertStatus(422);
    }

    public function test_accept_suggestion_does_not_overwrite_started_operation_plan(): void
    {
        [$task, $molding] = $this->setupDelayedScenario('2025-08-08');

        $molding->refresh();
        $originalPlanEnd = $molding->plan_end->format('Y-m-d H:i:s');
        $this->assertSame('running', $molding->status);

        $suggestion = \App\Models\ScheduleSuggestion::firstOrFail();
        $this->api($this->token)->postJson("/api/schedule/suggestions/{$suggestion->id}/accept")
            ->assertOk();

        $molding->refresh();
        $this->assertSame(
            $originalPlanEnd,
            $molding->plan_end->format('Y-m-d H:i:s'),
            '顺延建议不能覆盖已开工工序的计划基线'
        );
        $this->assertSame($task->id, (int) $suggestion->fresh()->task_id);
    }

    public function test_accept_suggestion_protects_schedule_with_legacy_report_ledger(): void
    {
        [$task] = $this->setupDelayedScenario('2025-08-08');

        $paint = $task->fresh()->schedules()->where('op_type', RouteOp::OP_PAINT)->firstOrFail();
        $originalPlanStart = $paint->plan_start->format('Y-m-d H:i:s');
        WorkReport::create([
            'task_id' => $task->id,
            'schedule_id' => $paint->id,
            'product_id' => $task->product_id,
            'machine_id' => $paint->machine_id,
            'qty' => 10,
            'defect_qty' => 0,
            'sign' => 1,
            'report_by' => 1,
            'worker_id' => null,
            'report_time' => '2025-08-09 12:00:00',
            'work_date' => '2025-08-09',
            'source' => WorkReport::SOURCE_LEADER_BATCH,
        ]);

        $suggestion = \App\Models\ScheduleSuggestion::firstOrFail();
        $this->api($this->token)->postJson("/api/schedule/suggestions/{$suggestion->id}/accept")->assertOk();

        $this->assertSame(
            $originalPlanStart,
            $paint->fresh()->plan_start->format('Y-m-d H:i:s'),
            '已有报工流水的工序不能被顺延建议改写计划'
        );
    }

    public function test_accept_suggestion_rejects_resource_conflict_without_mutation(): void
    {
        [$task, $molding] = $this->setupDelayedScenario('2025-08-08');

        $taskB = $this->createTask('P-0001', 400);
        $this->saveSchedule($taskB, 'I-02', '2025-08-20 08:00:00')->assertOk();

        $task->load('schedules');
        $aPaint = $task->schedules->where('op_type', RouteOp::OP_PAINT)->firstOrFail();
        $bPaint = $taskB->fresh()->schedules()->where('op_type', RouteOp::OP_PAINT)->firstOrFail();
        $aPaint->refresh();
        $bPaint->update([
            'plan_start' => $aPaint->forecast_start,
            'plan_end' => $aPaint->forecast_end,
        ]);
        $originalPlanStart = $aPaint->plan_start->format('Y-m-d H:i:s');

        $suggestion = \App\Models\ScheduleSuggestion::first();
        $response = $this->api($this->token)->postJson("/api/schedule/suggestions/{$suggestion->id}/accept");

        $response->assertStatus(409);
        $response->assertJsonPath('data.errors.0.code', 'resource_conflict');
        $this->assertSame('pending', $suggestion->fresh()->status);
        $this->assertSame(
            $originalPlanStart,
            $aPaint->fresh()->plan_start->format('Y-m-d H:i:s'),
            '冲突时原计划不得被部分修改'
        );
    }

    public function test_dismiss_suggestion_keeps_plan(): void
    {
        [$task, $molding] = $this->setupDelayedScenario('2025-08-08');

        $suggestion = \App\Models\ScheduleSuggestion::first();
        $this->api($this->token)->postJson("/api/schedule/suggestions/{$suggestion->id}/dismiss")->assertOk();
        $this->assertSame('dismissed', $suggestion->fresh()->status);

        // 驳回不改排程：plan 保持原基线
        $molding->refresh();
        $this->assertSame('2025-08-09 09:34:30', $molding->plan_end->format('Y-m-d H:i:s'));
    }

    public function test_no_suggestion_when_forecast_within_deadline(): void
    {
        $this->setupDelayedScenario('2025-09-30'); // 交期充足，滞后报工不破交期

        $this->assertDatabaseCount('schedule_suggestions', 0);
    }
}
