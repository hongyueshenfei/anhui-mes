<?php

namespace Tests\Feature\Production;

use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 机台日任务与晚间日结报工验收。
 */
class MachineDailyReportTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private function createTodaySchedule(string $token): TaskSchedule
    {
        $response = $this->api($token)->postJson('/api/orders', [
            'customer_name' => '机台日结测试',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1000]],
        ])->assertStatus(201);
        $order = Order::with('lines.tasks')->findOrFail($response->json('data.id'));
        $task = $order->lines->first()->tasks->first();
        $this->api($token)->postJson('/api/orders/'.$order->id.'/route-confirm')->assertOk();
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $start = now()->startOfDay()->addHours(8);
        $this->api($token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => $start->format('Y-m-d H:i:s')]],
        ])->assertOk();

        return TaskSchedule::where('task_id', $task->id)->orderBy('seq')->firstOrFail();
    }

    public function test_machine_context_only_returns_fixed_schedule_for_requested_day(): void
    {
        $token = $this->loginToken('admin');
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $schedule = $this->createTodaySchedule($token);
        $date = now()->startOfDay();

        $schedule->update([
            'plan_start' => $date->copy()->addHours(8),
            'plan_end' => $date->copy()->addHours(12),
            'is_interrupted' => 0,
        ]);
        $response = $this->api($token)->getJson('/mobile/machines/'.$machine->code.'/report-context?date='.$date->toDateString());

        $response->assertOk()
            ->assertJsonPath('data.machine.code', 'I-01')
            ->assertJsonPath('data.work_date', $date->toDateString());
        $this->assertTrue(collect($response->json('data.tasks'))->contains('schedule_id', $schedule->id));
        $this->assertIsArray($response->json('data.shift_options'));
    }

    public function test_pc_daily_report_context_returns_schedule_rows_without_worker_dimension(): void
    {
        $token = $this->loginToken('admin');
        $schedule = $this->createTodaySchedule($token);
        $date = now()->startOfDay();
        $schedule->update([
            'plan_start' => $date->copy()->addHours(8),
            'plan_end' => $date->copy()->addHours(12),
            'is_interrupted' => 0,
        ]);

        $response = $this->api($token)->getJson('/api/reports/daily-context?work_date='.$date->toDateString());

        $response->assertOk()
            ->assertJsonPath('data.work_date', $date->toDateString())
            ->assertJsonPath('data.rows.0.schedule_id', $schedule->id)
            ->assertJsonPath('data.rows.0.task_id', $schedule->task_id)
            ->assertJsonPath('data.rows.0.order_no', $schedule->task->line->order->order_no)
            ->assertJsonStructure([
                'data' => [
                    'rows' => [['schedule_id', 'task_id', 'task_no', 'operation', 'machine', 'planned_qty', 'reported_qty', 'remaining_qty']],
                    'summary' => ['row_count', 'planned_qty', 'reported_qty', 'remaining_qty'],
                ],
            ]);
    }

    public function test_machine_report_context_rejects_maintenance_machine(): void
    {
        $token = $this->loginToken('admin');
        $machine = Machine::where('code', 'I-04')->firstOrFail();

        $response = $this->api($token)->getJson('/mobile/machines/'.$machine->code.'/report-context?date='.now()->toDateString());

        $response->assertStatus(409)
            ->assertJsonPath('data.error_code', 'machine_unavailable')
            ->assertJsonPath('data.machine_status', Machine::STATUS_MAINTENANCE);
        $this->assertStringContainsString('维修中', $response->json('message'));
    }

    public function test_daily_report_records_work_date_machine_schedule_and_is_idempotent(): void
    {
        $adminToken = $this->loginToken('admin');
        $schedule = $this->createTodaySchedule($adminToken);
        $machine = Machine::findOrFail($schedule->machine_id);
        $date = now()->startOfDay();
        $schedule->update([
            'plan_start' => $date->copy()->addHours(8),
            'plan_end' => $date->copy()->addHours(12),
            'is_interrupted' => 0,
        ]);
        $token = $this->loginToken('L001');
        $payload = [
            'task_id' => $schedule->task_id,
            'schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'qty' => 1,
            'source' => WorkReport::SOURCE_MACHINE_QR,
            'work_date' => $date->toDateString(),
            'shift_code' => 'day',
            'client_request_id' => 'daily-test-'.$schedule->id,
        ];

        $this->api($token)->postJson('/mobile/reports', $payload)->assertStatus(201);
        $this->api($token)->postJson('/mobile/reports', $payload)->assertStatus(201);

        $this->assertDatabaseHas('work_reports', [
            'task_id' => $schedule->task_id,
            'schedule_id' => $schedule->id,
            'machine_id' => $machine->id,
            'worker_id' => null,
            'work_date' => $date->toDateString(),
            'shift_code' => 'day',
            'client_request_id' => 'daily-test-'.$schedule->id,
        ]);
        $this->assertSame(1, WorkReport::where('client_request_id', 'daily-test-'.$schedule->id)->count());
    }

    public function test_pc_daily_context_shows_task_level_report_on_final_operation(): void
    {
        $token = $this->loginToken('admin');
        $schedule = $this->createTodaySchedule($token);
        $task = $schedule->task->fresh();
        $date = now()->startOfDay();
        $task->schedules()->update([
            'plan_start' => $date->copy()->addHours(8),
            'plan_end' => $date->copy()->addHours(12),
            'is_interrupted' => 0,
        ]);

        $this->api($token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => 100,
            'source' => WorkReport::SOURCE_LEADER_BATCH,
            'work_date' => $date->toDateString(),
        ])->assertStatus(201);

        $rows = collect(
            $this->api($token)->getJson('/api/reports/daily-context?work_date='.$date->toDateString())
                ->assertOk()
                ->json('data.rows')
        );
        $last = $rows->sortByDesc(fn ($row) => $row['operation']['seq'])->firstWhere('task_id', $task->id);
        $first = $rows->sortBy(fn ($row) => $row['operation']['seq'])->firstWhere('task_id', $task->id);

        $this->assertSame(100, $last['reported_qty']);
        $this->assertSame(0, $first['reported_qty']);
    }

    public function test_pc_daily_context_does_not_attach_task_report_to_visible_prefix_operation(): void
    {
        $token = $this->loginToken('admin');
        $schedule = $this->createTodaySchedule($token);
        $task = $schedule->task->fresh();
        $date = now()->startOfDay();
        $schedules = $task->schedules()->orderBy('seq')->get();

        // 只有首道工序在今天，末道工序在后续日期；任务级报工今天发生时，
        // 日结表不能按“当天最后一行”把成品量显示到首道工序。
        foreach ($schedules as $index => $row) {
            $start = $date->copy()->addDays($index)->addHours(8);
            $row->update([
                'plan_start' => $start,
                'plan_end' => $start->copy()->addHours(4),
                'is_interrupted' => 0,
            ]);
        }
        $this->api($token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => 100,
            'work_date' => $date->toDateString(),
        ])->assertStatus(201);

        $rows = collect(
            $this->api($token)->getJson('/api/reports/daily-context?work_date='.$date->toDateString())
                ->assertOk()
                ->json('data.rows')
        );
        $first = $rows->firstWhere('task_id', $task->id);
        $this->assertNotNull($first);
        $this->assertSame(1, (int) $first['operation']['seq']);
        $this->assertSame(0, $first['reported_qty']);
    }
}
