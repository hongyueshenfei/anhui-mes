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
 * 排产版本快照/回滚测试（D9/B6：整版接受/局部采纳/回滚支撑）
 * - 手工保存产生快照；回滚恢复 before 并解锁；回滚不可重复
 */
class VersionRollbackTest extends TestCase
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
            'customer_name' => '版本测试',
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产（订单-工艺-排产流程）
        $this->api($this->token)->postJson('/api/orders/'.$response->json('data.id').'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($response->json('data.id'))->lines->first()->id);
    }

    public function test_manual_save_creates_applied_version_with_snapshot(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ])->assertOk();

        $version = \App\Models\ScheduleVersion::first();
        $this->assertSame('manual', $version->type);
        $this->assertSame('applied', $version->status);
        // 首次排产：before 为空库快照、after 含新排程
        $this->assertArrayHasKey('schedules', $version->snapshot['before']);
        $this->assertEmpty($version->snapshot['before']['schedules']);
        $this->assertNotEmpty($version->snapshot['after']['schedules']);
        $this->assertArrayHasKey($task->id, $version->snapshot['after']['tasks']);
    }

    public function test_rollback_restores_unscheduled_task(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->first();

        $save = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ])->assertOk();
        $versionId = $save->json('data.version.id');

        // 回滚
        $this->api($this->token)->postJson("/api/schedule/versions/{$versionId}/rollback")->assertOk();

        $task = $task->fresh();
        $this->assertSame('unscheduled', $task->status);
        $this->assertNull($task->plan_start);
        $this->assertSame(0, $task->is_locked);
        $this->assertDatabaseCount('task_schedules', 0);

        $this->assertDatabaseHas('schedule_versions', ['id' => $versionId, 'status' => 'rolled_back']);
    }

    public function test_rollback_applied_version_twice_rejected(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->first();

        $save = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ])->assertOk();
        $versionId = $save->json('data.version.id');

        $this->api($this->token)->postJson("/api/schedule/versions/{$versionId}/rollback")->assertOk();
        $this->api($this->token)->postJson("/api/schedule/versions/{$versionId}/rollback")->assertStatus(409);
    }

    public function test_rollback_nonexistent_version_404(): void
    {
        $this->api($this->token)->postJson('/api/schedule/versions/999999/rollback')->assertStatus(404);
    }

    public function test_version_list_and_detail(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ])->assertOk();

        $list = $this->api($this->token)->getJson('/api/schedule/versions');
        $list->assertOk();
        $this->assertSame(1, $list->json('data.total'));

        $versionId = $list->json('data.data.0.id');
        $detail = $this->api($this->token)->getJson("/api/schedule/versions/{$versionId}");
        $detail->assertOk()->assertJsonPath('data.type', 'manual');
    }

    public function test_rollback_restores_order_status_to_pending(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->first();
        $orderId = $task->line->order_id;

        $save = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => '2025-09-01 08:00:00']],
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'scheduled']);

        $this->api($this->token)->postJson("/api/schedule/versions/{$save->json('data.version.id')}/rollback")->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'pending']);
    }

    public function test_version_snapshot_preserves_aps_fields_and_before_task_state(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $oldStart = now()->addDays(30)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $oldStart->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $oldMolding = $task->fresh()->schedules()->where('op_type', 'molding_injection')->firstOrFail();
        $forecastStart = $oldStart->copy()->addMinutes(12);
        $actualStart = $oldStart->copy()->addMinutes(18);
        $oldMolding->update([
            'forecast_start' => $forecastStart,
            'forecast_end' => $oldStart->copy()->addHours(6),
            'actual_start' => $actualStart,
            'actual_end' => null,
            'is_frozen' => 1,
            'changeover_minutes' => 77,
        ]);

        // 已产生实际进度的任务不能再被覆盖式重排；用另一项排产制造版本，
        // 验证 before 快照仍能保留 A 的实际字段。
        $taskB = $this->createTask();
        $machineB = Machine::where('code', 'I-02')->firstOrFail();
        $save = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskB->id,
                'machine_id' => $machineB->id,
                'plan_start' => $oldStart->copy()->addDays(2)->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $version = \App\Models\ScheduleVersion::findOrFail($save->json('data.version.id'));
        $beforeTask = $version->snapshot['before']['tasks'][(string) $task->id] ?? $version->snapshot['before']['tasks'][$task->id];
        $beforeMolding = collect($version->snapshot['before']['schedules'])
            ->firstWhere('task_id', $task->id);

        $this->assertSame(1, (int) $beforeTask['is_locked']);
        $this->assertSame('scheduled', $beforeTask['status']);
        $this->assertSame($oldStart->format('Y-m-d H:i:s'), $beforeTask['plan_start']);
        $this->assertSame($forecastStart->format('Y-m-d H:i:s'), $beforeMolding['forecast_start']);
        $this->assertSame($actualStart->format('Y-m-d H:i:s'), $beforeMolding['actual_start']);
        $this->assertSame(1, (int) $beforeMolding['is_frozen']);
        $this->assertSame(77, (int) $beforeMolding['changeover_minutes']);
    }

    public function test_rollback_protects_new_task_that_started_after_version(): void
    {
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $base = now()->addDays(30)->startOfDay()->addHours(8);

        // 先排 A，再排 B；B 的版本 before 没有 B，after 才首次出现 B。
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskA->id,
                'machine_id' => $machine->id,
                'plan_start' => $base->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$taskB->id],
            'reason' => '回滚保护测试',
        ])->assertOk();

        $scheduled = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskB->id,
                'machine_id' => $machine->id,
                'plan_start' => $base->copy()->addDays(2)->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $versionId = $scheduled->json('data.version.id');
        $bMolding = $taskB->fresh()->schedules()->where('op_type', 'molding_injection')->firstOrFail();
        $bMolding->update(['status' => TaskSchedule::STATUS_RUNNING]);

        $this->api($this->token)->postJson("/api/schedule/versions/{$versionId}/rollback")
            ->assertStatus(409)
            ->assertJsonPath('data.errors.0.code', 'running_schedule');

        $this->assertDatabaseHas('task_schedules', ['id' => $bMolding->id, 'status' => TaskSchedule::STATUS_RUNNING]);
        $this->assertSame('scheduled', $taskB->fresh()->status);
    }

    public function test_rollback_protects_actual_progress_even_when_schedule_status_is_pending(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $save = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => '2026-12-10 08:00:00',
            ]],
        ])->assertOk();

        $schedule = $task->fresh()->schedules()->where('op_type', 'molding_injection')->firstOrFail();
        $schedule->update([
            'status' => TaskSchedule::STATUS_PENDING,
            'actual_start' => '2026-12-10 08:05:00',
        ]);

        $this->api($this->token)->postJson("/api/schedule/versions/{$save->json('data.version.id')}/rollback")
            ->assertStatus(409)
            ->assertJsonPath('data.errors.0.code', 'running_schedule');

        $this->assertDatabaseHas('task_schedules', [
            'id' => $schedule->id,
            'actual_start' => '2026-12-10 08:05:00',
        ]);
    }

    public function test_rollback_protects_report_ledger_even_when_actual_markers_are_missing(): void
    {
        $task = $this->createTask();
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $save = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => '2026-12-15 08:00:00',
            ]],
        ])->assertOk();

        $schedule = $task->fresh()->schedules()->where('op_type', 'molding_injection')->firstOrFail();
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
            'report_time' => '2026-12-15 09:00:00',
            'work_date' => '2026-12-15',
            'source' => WorkReport::SOURCE_LEADER_BATCH,
        ]);

        $this->api($this->token)->postJson("/api/schedule/versions/{$save->json('data.version.id')}/rollback")
            ->assertStatus(409)
            ->assertJsonPath('data.errors.0.code', 'reported_task');

        $this->assertDatabaseHas('work_reports', ['schedule_id' => $schedule->id, 'qty' => 100]);
        $this->assertDatabaseHas('task_schedules', ['id' => $schedule->id]);
    }

    public function test_rollback_ignores_reported_task_unrelated_to_version(): void
    {
        $reportedTask = $this->createTask();
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $reportedTask->id,
                'machine_id' => $machine->id,
                'plan_start' => '2026-12-18 08:00:00',
            ]],
        ])->assertOk();
        $reportedSchedule = $reportedTask->fresh()->schedules()->where('op_type', 'molding_injection')->firstOrFail();
        WorkReport::create([
            'task_id' => $reportedTask->id,
            'schedule_id' => $reportedSchedule->id,
            'product_id' => $reportedTask->product_id,
            'machine_id' => $machine->id,
            'qty' => 100,
            'defect_qty' => 0,
            'sign' => 1,
            'report_by' => 1,
            'worker_id' => null,
            'report_time' => '2026-12-18 09:00:00',
            'work_date' => '2026-12-18',
            'source' => WorkReport::SOURCE_LEADER_BATCH,
        ]);

        // 版本只修改另一个工单；无关工单的报工不能把本版本回滚拦住。
        $targetTask = $this->createTask();
        $saved = $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $targetTask->id,
                'machine_id' => Machine::where('code', 'I-02')->value('id'),
                'plan_start' => '2026-12-20 08:00:00',
            ]],
        ])->assertOk();

        $this->api($this->token)->postJson("/api/schedule/versions/{$saved->json('data.version.id')}/rollback")
            ->assertOk();

        $this->assertDatabaseHas('work_reports', [
            'task_id' => $reportedTask->id,
            'schedule_id' => $reportedSchedule->id,
            'qty' => 100,
        ]);
        $this->assertDatabaseMissing('task_schedules', ['task_id' => $targetTask->id]);
    }
}
