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
 * 排产全流程联测（B9：甘特+明细双视图一致；整体链路 建单→排产→插单→回滚）
 */
class ScheduleFlowTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function createTask(string $productCode = 'P-0001', int $qty = 1900, string $customer = '流程测试'): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => $customer,
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产（订单-工艺-排产流程）
        $this->api($this->token)->postJson('/api/orders/'.$response->json('data.id').'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($response->json('data.id'))->lines->first()->id);
    }

    public function test_timeline_and_detail_dual_view_consistent(): void
    {
        $b = now()->addDays(30)->startOfDay()->addHours(8);
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0002', 2800);

        $machineI = Machine::where('code', 'I-01')->first();
        $machineB = Machine::where('code', 'B-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [
                ['task_id' => $taskA->id, 'machine_id' => $machineI->id, 'plan_start' => $b->format('Y-m-d 08:00:00')],
                ['task_id' => $taskB->id, 'machine_id' => $machineB->id, 'plan_start' => $b->copy()->addDays(3)->format('Y-m-d 08:00:00')],
            ],
        ])->assertOk();

        // 甘特时间线
        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $b->copy()->subDays(1)->format('Y-m-d'),
            'end' => $b->copy()->addDays(7)->format('Y-m-d'),
        ]));
        $timeline->assertOk();

        $machines = collect($timeline->json('data.machines'));
        $iRow = $machines->firstWhere('code', 'I-01');
        $bRow = $machines->firstWhere('code', 'B-01');
        $this->assertNotNull($iRow);
        $this->assertNotNull($bRow);

        $iSched = collect($iRow['schedules'])->firstWhere('task_id', $taskA->id);
        $bSched = collect($bRow['schedules'])->firstWhere('task_id', $taskB->id);
        $this->assertNotNull($iSched, 'I-01 时间线应含 A 的成型排程');
        $this->assertNotNull($bSched, 'B-01 时间线应含 B 的成型排程');

        // 明细视图：与甘特数据一致（机台/起止/状态）
        $detailA = $this->api($this->token)->getJson('/api/schedule/detail?task_id='.$taskA->id)->assertOk()->json('data');
        $moldingA = collect($detailA['schedules'])->firstWhere('op_type', 'molding_injection');

        $this->assertSame($iSched['plan_start'], $moldingA['plan_start'], '甘特与明细计划开始一致');
        $this->assertSame($iSched['plan_end'], $moldingA['plan_end'], '甘特与明细计划结束一致');
        $this->assertSame($machineI->id, $moldingA['machine_id'], '明细机台一致');
        $this->assertSame($iSched['status'], $moldingA['status'], '状态一致');
        $this->assertSame($taskA->task_no, $moldingA['task_no'] ?? $detailA['task']['task_no']);

        // 甘特返回班制配置（前端绘制白/夜班背景）
        $this->assertArrayHasKey('two_shift', $timeline->json('data.shift'));
    }

    public function test_detail_returns_per_operation_report_progress_and_actual_times(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => now()->addDays(30)->format('Y-m-d 08:00:00')]],
        ])->assertOk();

        $schedule = $task->fresh()->schedules()->orderBy('seq')->firstOrFail();
        $firstAt = now()->subMinutes(20)->format('Y-m-d H:i:s');
        $lastAt = now()->subMinutes(5)->format('Y-m-d H:i:s');
        $payload = [
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
        ];
        $this->api($this->token)->postJson('/api/reports', $payload + ['qty' => 900, 'report_time' => $firstAt])->assertStatus(201);
        $this->api($this->token)->postJson('/api/reports', $payload + ['qty' => 1000, 'report_time' => $lastAt])->assertStatus(201);

        $detail = $this->api($this->token)->getJson('/api/schedule/detail?task_id='.$task->id)->assertOk()->json('data');
        $row = collect($detail['schedules'])->firstWhere('id', $schedule->id);

        $this->assertSame(1900, $row['reported_qty']);
        $this->assertSame(100, $row['progress_pct']);
        $this->assertSame('done', $row['status']);
        $this->assertSame($firstAt, $row['actual_start']);
        $this->assertSame($lastAt, $row['actual_end']);
        $this->assertSame(1900, (int) WorkReport::where('schedule_id', $schedule->id)->sum('qty'));
    }

    public function test_timeline_returns_partial_report_progress_for_gantt_display(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $planStart = now()->addDays(30)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => $planStart->format('Y-m-d H:i:s')]],
        ])->assertOk();

        $schedule = $task->fresh()->schedules()->orderBy('seq')->firstOrFail();
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'qty' => 900,
            'report_time' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
        ])->assertStatus(201);

        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $planStart->copy()->subDay()->format('Y-m-d'),
            'end' => $planStart->copy()->addDays(2)->format('Y-m-d'),
        ]))->assertOk()->json('data.machines');
        $row = collect($timeline)->firstWhere('id', $machine->id);
        $item = collect($row['schedules'])->firstWhere('id', $schedule->id);

        $this->assertSame(900, $item['reported_qty']);
        $this->assertSame(47, $item['progress_pct']);
        $this->assertSame('running', $item['status']);
        $this->assertNotNull($item['actual_start']);
    }

    public function test_timeline_end_date_includes_the_whole_day(): void
    {
        $task = $this->createTask('P-0001', 400);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $planStart = now()->addDays(30)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $planStart->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $planStart->format('Y-m-d'),
            'end' => $planStart->format('Y-m-d'),
        ]))->assertOk()->json('data.machines');
        $row = collect($timeline)->firstWhere('id', $machine->id);

        $this->assertNotNull(
            collect($row['schedules'])->firstWhere('task_id', $task->id),
            'end=日期时应包含该日期全天的排程'
        );
    }

    public function test_timeline_keeps_historical_schedule_on_inactive_machine(): void
    {
        $task = $this->createTask('P-0001', 400);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $planStart = now()->subDays(2)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $planStart->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $machine->update(['is_active' => 0]);

        $machines = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $planStart->toDateString(),
            'end' => $planStart->copy()->addDay()->toDateString(),
        ]))->assertOk()->json('data.machines');
        $row = collect($machines)->firstWhere('id', $machine->id);

        $this->assertNotNull($row, '历史排程所在的停用机台仍应保留甘特行');
        $this->assertNotNull(collect($row['schedules'])->firstWhere('task_id', $task->id));
    }

    public function test_timeline_keeps_schedule_when_only_actual_report_is_in_window(): void
    {
        $task = $this->createTask('P-0001', 400);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $planStart = now()->addDays(30)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $planStart->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $schedule = $task->fresh()->schedules()->orderBy('seq')->firstOrFail();
        $reportAt = now()->subMinutes(5);

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'qty' => 100,
            'report_time' => $reportAt->format('Y-m-d H:i:s'),
        ])->assertStatus(201);

        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $reportAt->format('Y-m-d'),
            'end' => $reportAt->format('Y-m-d'),
        ]))->assertOk()->json('data.machines');
        $row = collect($timeline)->firstWhere('id', $machine->id);
        $item = collect($row['schedules'])->firstWhere('id', $schedule->id);

        $this->assertNotNull($item, '窗口内有实际报工时，即使计划在窗口外也应保留排程');
        $this->assertSame(100, $item['reported_qty']);
    }

    public function test_timeline_keeps_schedule_when_only_forecast_is_in_window(): void
    {
        $task = $this->createTask('P-0001', 400);
        $task->update(['route_ops_override' => [[
            'seq' => 1,
            'op_type' => 'molding_injection',
            'op_name' => '注塑成型',
            'qty' => 400,
        ]]]);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $planStart = now()->subDays(4)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $planStart->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $schedule = $task->fresh()->schedules()->firstOrFail();
        $forecastStart = now()->startOfDay()->addHours(8);
        $forecastEnd = $forecastStart->copy()->addHours(2);
        $schedule->update([
            'plan_start' => $planStart,
            'plan_end' => $planStart->copy()->addHour(),
            'forecast_start' => $forecastStart,
            'forecast_end' => $forecastEnd,
        ]);

        $machines = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => now()->toDateString(),
            'end' => now()->addDay()->toDateString(),
        ]))->assertOk()->json('data.machines');
        $row = collect($machines)->firstWhere('id', $machine->id);

        $this->assertNotNull(
            collect($row['schedules'])->firstWhere('id', $schedule->id),
            '计划已结束但预测仍在当前窗口的排程不能从甘特图消失'
        );
    }

    public function test_timeline_does_not_leak_schedule_without_forecast_into_other_window(): void
    {
        $task = $this->createTask('P-0001', 400);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $planStart = now()->addDays(60)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $planStart->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $machines = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => now()->addDays(10)->toDateString(),
            'end' => now()->addDays(11)->toDateString(),
        ]))->assertOk()->json('data.machines');

        $leaked = collect($machines)
            ->flatMap(fn (array $row) => $row['schedules'])
            ->firstWhere('id', $task->fresh()->schedules()->firstOrFail()->id);
        $this->assertNull($leaked, '无预测时间的远期排程不能因空值条件被泄漏到当前时间窗');
    }

    public function test_timeline_conflict_ignores_actual_completed_schedule(): void
    {
        $base = now()->addDays(30)->startOfDay()->addHours(8);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $taskA = $this->createTask('P-0001', 400);
        $taskB = $this->createTask('P-0001', 400);
        $moldingOnly = [[
            'seq' => 1,
            'op_type' => 'molding_injection',
            'op_name' => '注塑成型',
            'qty' => 400,
        ]];
        $taskA->update(['route_ops_override' => $moldingOnly]);
        $taskB->update(['route_ops_override' => $moldingOnly]);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => $base->format('Y-m-d H:i:s')]],
        ])->assertOk();
        $a = $taskA->fresh()->schedules()->firstOrFail();
        $a->update([
            'status' => 'done',
            'actual_start' => $base,
            'actual_end' => $base->copy()->addHour(),
        ]);

        // A 已实际完工，B 与 A 的原计划区间重叠，但不应再被标红。
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => $base->format('Y-m-d H:i:s')]],
        ])->assertOk();

        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $base->toDateString(),
            'end' => $base->copy()->addDay()->toDateString(),
        ]))->assertOk()->json('data.machines');
        $items = collect($timeline)->firstWhere('id', $machine->id)['schedules'];
        $this->assertSame(0, collect($items)->firstWhere('id', $a->id)['is_conflict']);
        $this->assertSame(0, collect($items)->firstWhere('task_id', $taskB->id)['is_conflict']);
    }

    public function test_timeline_conflict_uses_forecast_extension(): void
    {
        $base = now()->addDays(30)->startOfDay()->addHours(8);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $taskA = $this->createTask('P-0001', 400);
        $taskB = $this->createTask('P-0001', 400);
        $moldingOnly = [[
            'seq' => 1,
            'op_type' => 'molding_injection',
            'op_name' => '注塑成型',
            'qty' => 400,
        ]];
        $taskA->update(['route_ops_override' => $moldingOnly]);
        $taskB->update(['route_ops_override' => $moldingOnly]);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => $base->format('Y-m-d H:i:s')]],
        ])->assertOk();
        $a = $taskA->fresh()->schedules()->firstOrFail();
        $a->update(['forecast_end' => $base->copy()->addHours(8)]);

        // 先放到预测区间之后，绕过保存时的冲突校验，再模拟计划员形成了旧数据重叠。
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskB->id, 'machine_id' => $machine->id, 'plan_start' => $base->copy()->addHours(10)->format('Y-m-d H:i:s')]],
        ])->assertOk();
        $b = $taskB->fresh()->schedules()->firstOrFail();
        $b->update([
            'plan_start' => $base->copy()->addHours(6),
            'plan_end' => $base->copy()->addHours(7),
        ]);

        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $base->toDateString(),
            'end' => $base->copy()->addDay()->toDateString(),
        ]))->assertOk()->json('data.machines');
        $items = collect($timeline)->firstWhere('id', $machine->id)['schedules'];
        $this->assertSame(1, collect($items)->firstWhere('id', $a->id)['is_conflict']);
        $this->assertSame(1, collect($items)->firstWhere('id', $b->id)['is_conflict']);
    }

    public function test_task_level_report_is_shown_once_on_final_operation(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $planStart = now()->addDays(30)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $planStart->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        // 任务级报工没有选择工序，按成品口径应归到任务最后一道工序的甘特展示。
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => 1900,
            'report_time' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
        ])->assertStatus(201);

        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $planStart->copy()->subDay()->format('Y-m-d'),
            'end' => $planStart->copy()->addDays(6)->format('Y-m-d'),
        ]))->assertOk()->json('data.machines');
        $machineRows = collect($timeline);
        $schedules = $task->fresh()->schedules;
        $last = $schedules->sortByDesc('seq')->first();
        $first = $schedules->sortBy('seq')->first();
        $lastItem = collect($machineRows->firstWhere('id', $last->machine_id)['schedules'])->firstWhere('id', $last->id);
        $firstItem = collect($machineRows->firstWhere('id', $first->machine_id)['schedules'])->firstWhere('id', $first->id);

        $this->assertSame(1900, $lastItem['reported_qty']);
        $this->assertSame(100, $lastItem['progress_pct']);
        $this->assertSame('done', $lastItem['status']);
        $this->assertNotNull($lastItem['actual_start']);
        $this->assertSame(0, $firstItem['reported_qty'], '任务级成品报工不能重复计入首道工序');

        $detail = $this->api($this->token)->getJson('/api/schedule/detail?task_id='.$task->id)
            ->assertOk()->json('data');
        $detailLast = collect($detail['schedules'])->firstWhere('id', $last->id);
        $this->assertSame(1900, $detailLast['reported_qty']);
        $this->assertSame(100, $detailLast['progress_pct']);
    }

    public function test_detail_attaches_task_report_to_latest_split_final_segment(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $planStart = now()->addDays(30)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $planStart->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $final = $task->fresh()->schedules->sortByDesc('seq')->firstOrFail();
        $final->update(['is_interrupted' => 1]);
        $current = $final->replicate();
        $current->is_interrupted = 0;
        $current->status = TaskSchedule::STATUS_PENDING;
        $current->done_qty = 0;
        $current->plan_start = $final->plan_end;
        $current->plan_end = $final->plan_end->copy()->addHour();
        $current->forecast_start = $current->plan_start;
        $current->forecast_end = $current->plan_end;
        $current->save();

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => 100,
            'report_time' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
        ])->assertStatus(201);

        $detail = $this->api($this->token)->getJson('/api/schedule/detail?task_id='.$task->id)
            ->assertOk()->json('data');
        $oldItem = collect($detail['schedules'])->firstWhere('id', $final->id);
        $currentItem = collect($detail['schedules'])->firstWhere('id', $current->id);

        $this->assertSame(0, $oldItem['reported_qty'], '历史末工序段不能承接任务级报工');
        $this->assertSame(100, $currentItem['reported_qty'], '任务级报工应挂到最新的当前末工序段');
    }

    public function test_schedule_correction_does_not_expose_negative_gantt_progress(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $planStart = now()->addDays(30)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $task->id,
                'machine_id' => $machine->id,
                'plan_start' => $planStart->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $first = $task->fresh()->schedules->sortBy('seq')->first();

        // 任务级成品量仍有 100，首道工序先报 100 后冲减 150，模拟拆段/错报更正
        // 导致单个排程段净量为负；界面进度不得出现负数。
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => 100,
            'report_time' => now()->subMinutes(5)->format('Y-m-d H:i:s'),
        ])->assertStatus(201);
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $first->id,
            'machine_id' => $first->machine_id,
            'qty' => 100,
        ])->assertStatus(201);
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $first->id,
            'machine_id' => $first->machine_id,
            'qty' => -150,
            'remark' => '负进度展示测试',
        ])->assertStatus(201);

        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $planStart->copy()->subDay()->format('Y-m-d'),
            'end' => $planStart->copy()->addDays(6)->format('Y-m-d'),
        ]))->assertOk()->json('data.machines');
        $item = collect($timeline)->firstWhere('id', $first->machine_id)['schedules'];
        $firstItem = collect($item)->firstWhere('id', $first->id);
        $this->assertSame(0, $firstItem['reported_qty']);
        $this->assertSame(0, $firstItem['progress_pct']);

        $detail = $this->api($this->token)->getJson('/api/schedule/detail?task_id='.$task->id)
            ->assertOk()->json('data');
        $detailFirst = collect($detail['schedules'])->firstWhere('id', $first->id);
        $this->assertSame(0, $detailFirst['reported_qty']);
        $this->assertSame(0, $detailFirst['progress_pct']);
        $this->assertSame(0, (int) $first->fresh()->done_qty);
    }

    public function test_timeline_marks_switch_gap_conflict_with_same_rule_as_save(): void
    {
        $base = now()->addDays(30)->startOfDay()->addHours(8);
        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $taskA = $this->createTask('P-0001', 400);
        $taskB = $this->createTask('P-0003', 400);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskA->id,
                'machine_id' => $machine->id,
                'plan_start' => $base->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskB->id,
                'machine_id' => $machine->id,
                'plan_start' => $base->copy()->addDays(10)->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();

        $aMolding = $taskA->fresh()->schedules->first();
        $bMolding = $taskB->fresh()->schedules->first();
        $bMolding->update([
            'plan_start' => $aMolding->plan_end->copy()->addMinutes(10),
            'plan_end' => $aMolding->plan_end->copy()->addHours(1),
        ]);

        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $base->format('Y-m-d'),
            'end' => $base->copy()->addDays(2)->format('Y-m-d'),
        ]))->assertOk()->json('data.machines');
        $items = collect($timeline)->firstWhere('id', $machine->id)['schedules'];

        $this->assertSame(1, collect($items)->firstWhere('id', $aMolding->id)['is_conflict']);
        $this->assertSame(1, collect($items)->firstWhere('id', $bMolding->id)['is_conflict']);
    }

    public function test_interrupted_history_does_not_mark_current_schedule_conflict(): void
    {
        $base = now()->addDays(30)->startOfDay()->addHours(8);
        $machineI = Machine::where('code', 'I-01')->firstOrFail();
        $machineI2 = Machine::where('code', 'I-02')->firstOrFail();
        $taskA = $this->createTask('P-0001', 400);
        $taskB = $this->createTask('P-0003', 400);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskA->id,
                'machine_id' => $machineI->id,
                'plan_start' => $base->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $archived = $taskA->fresh()->schedules->firstOrFail();
        $archived->update(['is_interrupted' => 1]);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskB->id,
                'machine_id' => $machineI2->id,
                'plan_start' => $base->copy()->addDays(2)->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $current = $taskB->fresh()->schedules->firstOrFail();
        $current->update([
            'machine_id' => $machineI->id,
            'plan_start' => $base->copy()->addMinutes(10),
            'plan_end' => $base->copy()->addHours(1),
        ]);

        $timeline = $this->api($this->token)->getJson('/api/schedule/timeline?'.http_build_query([
            'start' => $base->format('Y-m-d'),
            'end' => $base->copy()->addDays(2)->format('Y-m-d'),
        ]))->assertOk()->json('data.machines');
        $items = collect($timeline)->firstWhere('id', $machineI->id)['schedules'];

        $this->assertSame(0, collect($items)->firstWhere('id', $archived->id)['is_conflict']);
        $this->assertSame(0, collect($items)->firstWhere('id', $current->id)['is_conflict']);
    }

    public function test_task_pool_contains_unscheduled_only(): void
    {
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0001', 1900);
        $machine = Machine::where('code', 'I-01')->first();

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => now()->addDays(30)->format('Y-m-d 08:00:00')]],
        ])->assertOk();

        $pool = $this->api($this->token)->getJson('/api/schedule/task-pool')->assertOk()->json('data.items');

        $ids = collect($pool)->pluck('id');
        $this->assertNotContains($taskA->id, $ids->all(), '已排任务不出现在任务池');
        $this->assertContains($taskB->id, $ids->all());
    }

    public function test_workflow_order_schedule_detail_insert_rollback(): void
    {
        $b = now()->addDays(30)->startOfDay()->addHours(8);

        // 1. 建单（A 排上，X 待排）
        $taskA = $this->createTask('P-0001', 1900);
        $taskX = $this->createTask('P-0001', 3800, '插单客户');
        $machine = Machine::where('code', 'I-01')->first();

        // 2. 排产 A
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $machine->id, 'plan_start' => $b->format('Y-m-d 08:00:00')]],
        ])->assertOk();

        // 3. 订单状态聚合
        $this->assertDatabaseHas('orders', ['id' => $taskA->line->order_id, 'status' => 'scheduled']);

        // 4. 插单 X（等待策略）
        $confirm = $this->api($this->token)->postJson('/api/schedule/insert/confirm', [
            'task_id' => $taskX->id,
            'machine_id' => $machine->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'wait',
        ])->assertOk();

        $versionId = $confirm->json('data.version.id');

        // 5. A 已让出 30 分钟切换间隔，没有真实延期时不返回虚假的受影响订单
        $this->assertSame([], $confirm->json('data.affected_orders'));

        // 6. 排产结果页数据（任务明细）
        $this->api($this->token)->getJson('/api/schedule/detail?task_id='.$taskX->id)
            ->assertOk()
            ->assertJsonPath('data.task.status', 'scheduled');

        // 7. 回滚插单版本 → X 回待排
        $this->api($this->token)->postJson("/api/schedule/versions/{$versionId}/rollback")->assertOk();
        $this->assertSame('unscheduled', $taskX->fresh()->status);
        $this->assertDatabaseMissing('task_schedules', ['task_id' => $taskX->id]);

        // 8. A 恢复原排程且锁定仍在（保存即锁定语义）
        $this->assertSame(1, $taskA->fresh()->is_locked);
    }

    public function test_schedule_view_permissions(): void
    {
        // 工人：无排产查看/操作权限（排产为计划员职责）
        $worker = $this->loginToken('W001');
        $this->api($worker)->getJson('/api/schedule/task-pool')->assertStatus(403);
        $this->api($worker)->postJson('/api/schedule/lock', ['task_ids' => [1]])->assertStatus(403);

        // 班组长：只读
        $leader = $this->loginToken('L001');
        $this->api($leader)->getJson('/api/schedule/task-pool')->assertOk();
        $this->api($leader)->postJson('/api/schedule/lock', ['task_ids' => [1]])->assertStatus(403);

        // 计划员：可查看可操作
        $planner = $this->loginToken('P001');
        $this->api($planner)->getJson('/api/schedule/task-pool')->assertOk();
        $this->api($planner)->postJson('/api/schedule/unlock', ['task_ids' => [1], 'reason' => '权限测试'])
            ->assertStatus(200); // 任务不存在也能解锁（0 个受影响），但权限通过
    }
}
