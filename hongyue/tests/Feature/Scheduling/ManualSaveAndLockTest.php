<?php

namespace Tests\Feature\Scheduling;

use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 手工排产保存/冲突/锁定测试（二期验收 B3/B7）
 * - 保存即锁定；解锁留审计
 * - 同机台时间重叠 → 409 附冲突清单（产能不足红框的后端口径）
 * - force 强制排入 → 标记 is_overlap
 * - 不同机台并行任务互不冲突
 */
class ManualSaveAndLockTest extends TestCase
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
            'customer_name' => '手工排产测试',
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        $orderId = $response->json('data.id');
        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产（订单-工艺-排产流程）
        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);
    }

    public function test_save_locks_task_and_creates_version(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->first();

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ]);

        $response->assertOk()->assertJsonPath('data.version.type', 'manual');

        $task = $task->fresh();
        $this->assertSame(1, $task->is_locked, '保存即锁定');
        $this->assertSame('scheduled', $task->status);
        $this->assertSame('2025-08-01 08:00:00', $task->plan_start->format('Y-m-d H:i:s'));

        $this->assertDatabaseHas('schedule_versions', ['type' => 'manual', 'status' => 'applied']);
    }

    public function test_locked_task_cannot_be_rescheduled(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        // 已锁定任务重排 → 409
        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 10:00:00']],
        ]);

        $response->assertStatus(409);
        $this->assertContains('task_locked', collect($response->json('data.errors'))->pluck('code')->all());
    }

    public function test_unlock_records_audit_and_allows_reschedule(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        // 解锁（留痕）
        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id],
            'reason' => '调整交期需要重排',
        ])->assertOk();

        $this->assertSame(0, $task->fresh()->is_locked);
        $this->assertDatabaseHas('audit_logs', ['module' => 'scheduling', 'action' => 'unlock_task']);

        // 解锁后可重排
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 14:00:00']],
        ])->assertOk();

        $this->assertSame('2025-08-01 14:00:00', $task->fresh()->plan_start->format('Y-m-d H:i:s'));
    }

    public function test_stale_in_progress_without_actual_progress_can_be_rescheduled(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-08 10:00:00'));
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2026-09-15 08:00:00']],
        ])->assertOk();

        // 模拟存量异常：任务主表是 in_progress，但所有工序仍待执行、没有实际起止和报工流水。
        $task->fresh()->update(['status' => ProdTask::STATUS_IN_PROGRESS, 'is_locked' => 0]);

        $this->api($this->token)->getJson('/api/schedule/detail?task_id='.$task->id)
            ->assertOk()
            ->assertJsonPath('data.task.status', ProdTask::STATUS_SCHEDULED)
            ->assertJsonPath('data.task.status_label', '已排产');

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2026-09-16 08:00:00']],
        ])->assertOk();

        $this->assertSame(ProdTask::STATUS_SCHEDULED, $task->fresh()->status);
        $this->assertDatabaseCount('work_reports', 0);

    }

    public function test_future_schedule_outside_freeze_window_can_be_unlocked(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-12 08:00:00']],
        ])->assertOk();

        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id],
            'reason' => '冻结窗口外调整测试',
        ])->assertOk();

        $this->assertSame(0, (int) $task->fresh()->is_locked);
    }

    public function test_schedule_inside_freeze_window_cannot_be_unlocked_directly(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-06 08:00:00']],
        ])->assertOk();

        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id],
            'reason' => '冻结区保护测试',
        ])->assertStatus(409)->assertJsonPath('data.errors.0.code', 'frozen_schedule');

        $this->assertSame(1, (int) $task->fresh()->is_locked);
    }

    public function test_schedule_inside_freeze_window_can_be_exception_unlocked_with_reason(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-06 08:00:00']],
        ])->assertOk();

        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id],
            'reason' => '客户交期变更，计划员确认异常调整',
            'exception_unlock' => true,
        ])->assertOk();

        $this->assertSame(0, (int) $task->fresh()->is_locked);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'scheduling',
            'action' => 'unlock_task',
            'target_id' => $task->id,
        ]);
    }

    public function test_started_operation_cannot_be_overwritten_after_unlock(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $task->fresh()->schedules()->where('op_type', 'molding_injection')->update([
            'actual_start' => '2025-08-01 08:05:00',
        ]);
        $unlock = $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id],
            'reason' => '实际进度保护测试',
        ]);
        $unlock->assertStatus(409)->assertJsonPath('data.errors.0.code', 'running_schedule');
        $task->refresh()->update(['is_locked' => 0]);

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-02 08:00:00']],
        ]);

        $response->assertStatus(409)->assertJsonPath('data.errors.0.code', 'task_not_editable');
        $this->assertDatabaseHas('task_schedules', [
            'task_id' => $task->id,
            'actual_start' => '2025-08-01 08:05:00',
        ]);
    }

    public function test_report_ledger_protects_legacy_schedule_from_unlock_and_reschedule(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2026-12-20 08:00:00']],
        ])->assertOk();

        $schedule = $task->fresh()->schedules()->where('op_type', 'molding_injection')->firstOrFail();
        // 模拟存量异常：已有报工流水，但排程状态/actual 字段尚未被联动补齐。
        WorkReport::create([
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'product_id' => $task->product_id,
            'machine_id' => $machine->id,
            'qty' => 100,
            'defect_qty' => 0,
            'sign' => 1,
            'report_by' => 1,
            'worker_id' => null,
            'report_time' => '2026-12-20 09:00:00',
            'work_date' => '2026-12-20',
            'source' => WorkReport::SOURCE_LEADER_BATCH,
        ]);

        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id],
            'reason' => '存量报工保护测试',
        ])->assertStatus(409)->assertJsonPath('data.errors.0.code', 'reported_task');

        $task->refresh()->update(['is_locked' => 0]);
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2026-12-21 08:00:00']],
        ])->assertStatus(409)->assertJsonPath('data.errors.0.code', 'task_not_editable');

        $this->assertDatabaseHas('work_reports', ['schedule_id' => $schedule->id, 'qty' => 100]);
    }

    public function test_overlap_conflict_rejected_with_conflict_list(): void
    {
        $machine = Machine::where('code', 'I-01')->first();
        $taskA = $this->createTask('P-0001', 1900); // 08:00-13:15（400/h + 30min 换模）
        $taskB = $this->createTask('P-0001', 1900); // 与 A 重叠

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 09:00:00']],
        ]);

        $response->assertStatus(409);
        $conflicts = $response->json('data.errors');
        $this->assertNotEmpty($conflicts);
        $this->assertContains('resource_conflict', collect($conflicts)->pluck('code')->all());
        $this->assertStringContainsString('重叠', $conflicts[0]['message']);
        $this->assertSame('注塑成型', $conflicts[0]['op_name_a'], '冲突应标明本次调整的工序');
        $this->assertSame('注塑成型', $conflicts[0]['op_name_b'], '冲突应标明被占用任务的工序');
        $this->assertSame(1, (int) $conflicts[0]['seq_a']);
        $this->assertSame(1, (int) $conflicts[0]['seq_b']);
        $this->assertDatabaseCount('task_schedules', 4); // 仅 A 的 4 道工序落库
    }

    public function test_single_operation_adjust_does_not_rebuild_other_operations(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));
        $task = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $before = TaskSchedule::where('task_id', $task->id)->get()->keyBy('seq');
        $effect = $before->get(3);

        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id],
            'reason' => '单工序提前排队测试',
        ])->assertOk();

        $this->api($this->token)->postJson('/api/schedule/operation-adjust', [
            'schedule_id' => $effect->id,
            'machine_id' => Machine::where('code', 'P-02')->value('id'),
            'plan_start' => '2025-08-01 08:00:00',
        ])->assertOk();

        $after = TaskSchedule::where('task_id', $task->id)->get()->keyBy('seq');
        $this->assertSame(
            $before->get(1)->plan_start->format('Y-m-d H:i:s'),
            $after->get(1)->plan_start->format('Y-m-d H:i:s'),
            '单工序调整不能重算第一道工序',
        );
        $this->assertSame(
            $before->get(2)->plan_start->format('Y-m-d H:i:s'),
            $after->get(2)->plan_start->format('Y-m-d H:i:s'),
            '单工序调整不能重算第二道工序',
        );
        $this->assertSame(
            $before->get(4)->plan_start->format('Y-m-d H:i:s'),
            $after->get(4)->plan_start->format('Y-m-d H:i:s'),
            '单工序调整不能重算后续工序',
        );
        $this->assertSame('2025-08-01 08:00:00', $after->get(3)->plan_start->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) $task->fresh()->is_locked, '单工序调整保存后仍锁定任务');
    }

    public function test_operation_edge_adjustment_changes_only_requested_boundary(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-05 10:00:00'));
        $task = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'P-02')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => Machine::where('code', 'I-01')->value('id'), 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $effect = TaskSchedule::where('task_id', $task->id)->where('seq', 3)->firstOrFail();
        $originalEnd = $effect->plan_end->format('Y-m-d H:i:s');
        $targetStart = $effect->plan_start->copy()->subDay();

        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id],
            'reason' => '工序边界调整测试',
        ])->assertOk();

        $this->api($this->token)->postJson('/api/schedule/operation-adjust', [
            'schedule_id' => $effect->id,
            'machine_id' => $machine->id,
            'plan_start' => $targetStart->format('Y-m-d H:i:s'),
            'plan_end' => $originalEnd,
            'edge' => 'start',
        ])->assertOk();

        $afterStart = $effect->fresh();
        $this->assertSame($targetStart->format('Y-m-d H:i:s'), $afterStart->plan_start->format('Y-m-d H:i:s'));
        $this->assertSame($originalEnd, $afterStart->plan_end->format('Y-m-d H:i:s'), '左侧手柄不能改变结束时间');

        $originalStart = $afterStart->plan_start->format('Y-m-d H:i:s');
        $targetEnd = $afterStart->plan_end->copy()->addHours(2)->format('Y-m-d H:i:s');
        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id],
            'reason' => '工序边界调整测试',
        ])->assertOk();

        $this->api($this->token)->postJson('/api/schedule/operation-adjust', [
            'schedule_id' => $effect->id,
            'machine_id' => $machine->id,
            'plan_start' => $originalStart,
            'plan_end' => $targetEnd,
            'edge' => 'end',
        ])->assertOk();

        $afterEnd = $effect->fresh();
        $this->assertSame($originalStart, $afterEnd->plan_start->format('Y-m-d H:i:s'), '右侧手柄不能改变开始时间');
        $this->assertSame($targetEnd, $afterEnd->plan_end->format('Y-m-d H:i:s'));
    }

    public function test_actual_completed_schedule_releases_planned_machine_occupancy(): void
    {
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0001', 1900);
        $moldingOnly = [[
            'seq' => 1,
            'op_type' => 'molding_injection',
            'op_name' => '注塑成型',
            'qty' => 1900,
        ]];
        $taskA->update(['route_ops_override' => $moldingOnly]);
        $taskB->update(['route_ops_override' => $moldingOnly]);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        // A 的计划区间仍然很长，但实际已在 09:00 完工；B 可以复用原计划尾部的机台。
        TaskSchedule::where('task_id', $taskA->id)
            ->where('op_type', 'molding_injection')
            ->update([
                'status' => TaskSchedule::STATUS_DONE,
                'actual_start' => '2025-08-01 08:00:00',
                'actual_end' => '2025-08-01 09:00:00',
            ]);

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 09:30:00']],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('task_schedules', ['task_id' => $taskB->id]);
    }

    public function test_forecast_late_schedule_still_blocks_machine(): void
    {
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0001', 1900);
        $moldingOnly = [[
            'seq' => 1,
            'op_type' => 'molding_injection',
            'op_name' => '注塑成型',
            'qty' => 1900,
        ]];
        $taskA->update(['route_ops_override' => $moldingOnly]);
        $taskB->update(['route_ops_override' => $moldingOnly]);
        $base = '2026-12-01 08:00:00';

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => $base]],
        ])->assertOk();
        TaskSchedule::where('task_id', $taskA->id)
            ->where('op_type', 'molding_injection')
            ->update(['forecast_end' => '2026-12-01 16:00:00']);

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => '2026-12-01 10:00:00']],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.errors.0.code', 'resource_conflict')
            ->assertJsonPath('data.errors.0.resource', 'machine:'.$machine->id);
    }

    public function test_reverse_order_machine_switch_gap_under_thirty_minutes_is_rejected(): void
    {
        $machine = Machine::where('code', 'I-01')->first();
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0001', 1900);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $aMold = TaskSchedule::where('task_id', $taskA->id)->where('op_type', 'molding_injection')->firstOrFail();
        // B 的成型排程从 A 结束后 15 分钟开始，验证“旧行在前、新行在后”的反向顺序也能拦截。
        $bStart = $aMold->plan_end->copy()->addMinutes(15);
        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => $bStart->format('Y-m-d H:i:s')]],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.errors.0.code', 'resource_conflict')
            ->assertJsonPath('data.errors.0.resource', 'machine:'.$machine->id);
    }

    public function test_force_save_marks_overlap(): void
    {
        $machine = Machine::where('code', 'I-01')->first();
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0001', 1900);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 09:00:00']],
            'force' => true,
            'note' => '紧急插单确认',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('task_schedules', ['task_id' => $taskB->id, 'is_overlap' => 1]);
    }

    public function test_parallel_machines_no_conflict(): void
    {
        $machineI = Machine::where('code', 'I-01')->first();
        $machineB = Machine::where('code', 'B-01')->first();
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0002', 2800); // 吹塑，适用 B 机台

        // A 在 8/1 开始；B 在 A 全链结束后开始（各人工产线按自身资源互斥）
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machineI->id, 'plan_start' => '2025-08-01 08:00:00']],
        ])->assertOk();

        $aEnd = TaskSchedule::where('task_id', $taskA->id)->max('plan_end');

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskB->id, 'machine_id' => $machineB->id, 'plan_start' => $aEnd]],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('task_schedules', 8);
    }

    public function test_batch_save_with_conflict_rejected_atomically(): void
    {
        $machine = Machine::where('code', 'I-01')->first();
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0001', 1900);

        $response = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [
                ['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:00:00'],
                ['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => '2025-08-01 08:30:00'],
            ],
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('task_schedules', 0); // 批量保存应原子化：冲突时整体不落库
    }
}
