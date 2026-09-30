<?php

namespace Tests\Feature\Scheduling;

use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use App\Modules\Scheduling\Services\ScheduleEngineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 插单测试（二期验收 B4：等待/打断双策略 + 级联顺延 + 受影响清单 + 确认/回滚）
 *
 * 口径（P-0001 @380/h + 换模 30min，two_shift 连续；1900 件成型 5.5h，3800 件成型 10.5h）：
 * 时间基准 = 未来第 30 天的 08:00（保证"未开始"场景不受真实时钟影响）；
 * "已开始打断"场景单独用 now-2h 构造。
 */
class InsertShiftTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /**
     * 未来排产时间基准（第 30 天 08:00）
     */
    private function base(): Carbon
    {
        return now()->addDays(30)->startOfDay()->addHours(8);
    }

    private function at(string $date, string $time = '08:00:00'): string
    {
        return Carbon::parse($date)->format('Y-m-d').' '.$time;
    }

    private function createTask(string $productCode = 'P-0001', int $qty = 1900): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '插单测试',
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        $orderId = $response->json('data.id');
        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产（订单-工艺-排产流程）
        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);
    }

    private function machine(string $code): Machine
    {
        return Machine::where('code', $code)->first();
    }

    /**
     * 机台队列：任务依次每隔 stepDays 天 08:00 排入（默认 1 天；人工线链长约 5 天，多任务需错开）
     */
    private function prepareMachineQueue(array $tasks, int $stepDays = 1): void
    {
        $items = [];
        $b = $this->base();
        foreach ($tasks as $i => $task) {
            $items[] = ['task_id' => $task->id, 'machine_id' => $this->machine('I-01')->id, 'plan_start' => $b->copy()->addDays($i * $stepDays)->format('Y-m-d 08:00:00')];
        }
        $resp = $this->api($this->token)->postJson('/api/schedule/manual-save', ['items' => $items]);
        $resp->assertOk();
    }

    public function test_insert_wait_strategy_defers_new_task_without_reordering_clear_slots(): void
    {
        $b = $this->base();
        // A: 基准日 08:00 起；C: 次日 08:00 起（按当前产能验证人工线链的级联冲突）
        $taskA = $this->createTask('P-0001', 1900);
        $taskC = $this->createTask('P-0001', 1900);
        $this->prepareMachineQueue([$taskA, $taskC], 1);

        // X: qty 3800 = 10.5h，插基准日 08:00 → 等待后 14:00 开始（含 30 分钟切换间隔）
        $taskX = $this->createTask('P-0001', 3800);

        $preview = $this->api($this->token)->postJson('/api/schedule/insert', [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'wait',
        ]);

        $preview->assertOk();

        // 等待策略：插入任务在 A 结束并留出 30 分钟切换间隔后开始
        $chainStart = collect($preview->json('data.insert_chain'))->pluck('plan_start')->min();
        $this->assertSame($b->copy()->addHours(6)->addSeconds(45)->format('Y-m-d H:i:s'), $chainStart, '等待策略下插入任务顺延到机台占用结束并留出切换间隔');

        // A 已让出 30 分钟切换间隔，当前场景不需要再反向移动 A 或 C
        $changed = collect($preview->json('data.changed_tasks'));
        $this->assertNull($changed->firstWhere('task_id', $taskA->id), 'A 已满足切换间隔，不应被反向顺延');
        $this->assertNull($changed->firstWhere('task_id', $taskC->id), 'C 无资源冲突，不应被级联顺延');
        $this->assertSame([], $preview->json('data.affected_orders'));

        // ---- 确认生效 ----
        $confirm = $this->api($this->token)->postJson('/api/schedule/insert/confirm', [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'wait',
        ]);

        $confirm->assertOk()->assertJsonPath('data.version.type', 'insert');
        $this->assertSame([], $confirm->json('data.affected_orders'));

        // X 已排产并锁定，且位于 14:00:45（未被后续顺延——插单任务锚定）
        $taskX = $taskX->fresh();
        $this->assertSame('scheduled', $taskX->status);
        $this->assertSame(1, $taskX->is_locked);
        $this->assertSame($b->copy()->addHours(6)->addSeconds(45)->format('Y-m-d H:i:s'), $taskX->plan_start->format('Y-m-d H:i:s'));

        // C 原计划与 X 的后续工序没有资源冲突，不应无故移动
        $cMolding = $taskC->fresh()->schedules()->where('op_type', 'molding_injection')->first();
        $this->assertSame(
            $b->copy()->addDays(1)->format('Y-m-d H:i:s'),
            $cMolding->plan_start->format('Y-m-d H:i:s'),
            '无资源冲突的后续任务不应被无故顺延'
        );
    }

    public function test_paused_schedule_keeps_original_plan_for_display_but_releases_resource(): void
    {
        $b = $this->base();
        $pausedTask = $this->createTask('P-0001', 1900);
        $this->prepareMachineQueue([$pausedTask]);

        $pausedTask->update(['status' => ProdTask::STATUS_PAUSED]);
        $pausedTask->fresh()->schedules()->update(['status' => TaskSchedule::STATUS_PAUSED]);

        $newTask = $this->createTask('P-0001', 1900);
        $preview = $this->api($this->token)->postJson('/api/schedule/insert', [
            'task_id' => $newTask->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'wait',
        ])->assertOk();

        $chainStart = collect($preview->json('data.insert_chain'))->pluck('plan_start')->min();
        $this->assertSame(
            $b->format('Y-m-d H:i:s'),
            $chainStart,
            '已暂停排程保留原计划供甘特展示，但不应阻塞新的插单'
        );
        $this->assertSame(
            TaskSchedule::STATUS_PAUSED,
            $pausedTask->fresh()->schedules()->where('op_type', 'molding_injection')->value('status')
        );
    }

    public function test_insert_wait_uses_actual_end_as_current_occupancy_boundary(): void
    {
        $b = $this->base();
        $taskA = $this->createTask('P-0001', 1900);
        $taskX = $this->createTask('P-0001', 1900);
        $moldingOnly = [[
            'seq' => 1,
            'op_type' => 'molding_injection',
            'op_name' => '注塑成型',
            'qty' => 1900,
        ]];
        $taskA->update(['route_ops_override' => $moldingOnly]);
        $taskX->update(['route_ops_override' => $moldingOnly]);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $this->machine('I-01')->id, 'plan_start' => $b->format('Y-m-d 08:00:00')]],
        ])->assertOk();
        $actualEnd = $b->copy()->addHour();
        $taskA->fresh()->schedules()->firstOrFail()->update([
            'actual_start' => $b,
            'actual_end' => $actualEnd,
            'status' => 'running',
        ]);

        $preview = $this->api($this->token)->postJson('/api/schedule/insert', [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'wait',
        ])->assertOk();

        $chainStart = collect($preview->json('data.insert_chain'))->pluck('plan_start')->min();
        $this->assertSame(
            $actualEnd->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            $chainStart,
            '等待插单应从实际结束后计算机台切换间隔'
        );
    }

    public function test_insert_wait_uses_forecast_end_when_it_is_later_than_plan(): void
    {
        $b = $this->base();
        $taskA = $this->createTask('P-0001', 1900);
        $taskX = $this->createTask('P-0001', 1900);
        $moldingOnly = [[
            'seq' => 1,
            'op_type' => 'molding_injection',
            'op_name' => '注塑成型',
            'qty' => 1900,
        ]];
        $taskA->update(['route_ops_override' => $moldingOnly]);
        $taskX->update(['route_ops_override' => $moldingOnly]);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $this->machine('I-01')->id, 'plan_start' => $b->format('Y-m-d 08:00:00')]],
        ])->assertOk();
        $forecastEnd = $b->copy()->addHours(8);
        $taskA->fresh()->schedules()->firstOrFail()->update(['forecast_end' => $forecastEnd]);

        $preview = $this->api($this->token)->postJson('/api/schedule/insert', [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 13:00:00'),
            'strategy' => 'wait',
        ])->assertOk();

        $chainStart = collect($preview->json('data.insert_chain'))->pluck('plan_start')->min();
        $this->assertSame(
            $forecastEnd->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            $chainStart,
            '等待插单应从预测结束后计算机台切换间隔'
        );
    }

    public function test_insert_cannot_interrupt_schedule_with_actual_progress(): void
    {
        $b = $this->base();
        $taskA = $this->createTask('P-0001', 1900);
        $this->prepareMachineQueue([$taskA]);
        $taskA->fresh()->schedules()->where('op_type', 'molding_injection')->update([
            'actual_start' => $b->copy()->addMinutes(5),
            'status' => 'running',
        ]);

        $taskX = $this->createTask('P-0001', 1900);
        $response = $this->api($this->token)->postJson('/api/schedule/insert', [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'interrupt',
            'force_interrupt' => true,
        ]);

        $response->assertStatus(409)->assertJsonPath('data.errors.0.code', 'cannot_interrupt_running');
    }

    public function test_sweep_rejects_conflict_that_would_move_started_task(): void
    {
        $engine = app(ScheduleEngineService::class);
        $rows = [
            [
                'task_id' => 1,
                'task_no' => 'TASK-A',
                'machine_id' => 10,
                'mold_id' => null,
                'plan_start' => Carbon::parse('2026-10-01 08:00:00'),
                'plan_end' => Carbon::parse('2026-10-01 12:00:00'),
                'is_interrupted' => 0,
                'status' => 'pending',
            ],
            [
                'task_id' => 2,
                'task_no' => 'TASK-B',
                'machine_id' => 10,
                'mold_id' => null,
                'plan_start' => Carbon::parse('2026-10-01 09:00:00'),
                'plan_end' => Carbon::parse('2026-10-01 11:00:00'),
                'actual_start' => Carbon::parse('2026-10-01 09:05:00'),
                'is_interrupted' => 0,
                'status' => 'running',
            ],
        ];

        $this->expectException(ScheduleException::class);
        $this->expectExceptionMessage('已开工工序冲突');
        $engine->sweep($rows);
    }

    public function test_sweep_does_not_add_machine_switch_gap_to_shared_mold(): void
    {
        $engine = app(ScheduleEngineService::class);
        $firstStart = Carbon::parse('2026-10-01 08:00:00');
        $firstEnd = $firstStart->copy()->addHour();
        $secondStart = $firstEnd->copy()->addMinutes(5);
        $rows = [
            [
                'task_id' => 1,
                'task_no' => 'TASK-A',
                'seq' => 1,
                'machine_id' => 10,
                'mold_id' => 99,
                'plan_start' => $firstStart,
                'plan_end' => $firstEnd,
                'is_interrupted' => 0,
                'status' => 'pending',
                'task_status' => 'scheduled',
                'actual_start' => null,
                'actual_end' => null,
                'shift_mode' => 'two_shift',
                'periods' => [],
                'capacity' => 0,
                'qty' => 1,
                'mold_change' => 0,
            ],
            [
                'task_id' => 2,
                'task_no' => 'TASK-B',
                'seq' => 1,
                'machine_id' => 11,
                'mold_id' => 99,
                'plan_start' => $secondStart,
                'plan_end' => $secondStart->copy()->addHour(),
                'is_interrupted' => 0,
                'status' => 'pending',
                'task_status' => 'scheduled',
                'actual_start' => null,
                'actual_end' => null,
                'shift_mode' => 'two_shift',
                'periods' => [],
                'capacity' => 0,
                'qty' => 1,
                'mold_change' => 0,
            ],
        ];

        $engine->sweep($rows);

        $this->assertSame(
            $secondStart->format('Y-m-d H:i:s'),
            Carbon::parse($rows[1]['plan_start'])->format('Y-m-d H:i:s'),
            '同模具跨机台连续使用不应额外增加机台切换间隔'
        );
    }

    public function test_insert_interrupt_shifts_unstarted_task_entirely(): void
    {
        $b = $this->base();
        // A: 3800 件 08:00-18:30（未开始）
        $taskA = $this->createTask('P-0001', 3800);
        $this->prepareMachineQueue([$taskA]);

        $taskX = $this->createTask('P-0001', 1900);

        $previewBody = [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'interrupt',
            'force_interrupt' => true, // A 保存即锁定，打断需显式确认
        ];

        $preview = $this->api($this->token)->postJson('/api/schedule/insert', $previewBody);

        $preview->assertOk()->assertJsonPath('data.interrupted.task_id', $taskA->id);
        $this->assertSame(0, $preview->json('data.interrupted.done_qty'), '未开始任务无已完成部分');
        $this->assertSame(3800, $preview->json('data.interrupted.remaining_qty'));

        // 插入任务占据原位置
        $chainStart = collect($preview->json('data.insert_chain'))->pluck('plan_start')->min();
        $this->assertSame($b->format('Y-m-d 08:00:00'), $chainStart);

        // 确认后：A 剩余量从插入任务整链结束后开始（1800 件链 end = 次日 13:50:30）
        $this->api($this->token)->postJson('/api/schedule/insert/confirm', $previewBody)->assertOk();

        $aMolding = $taskA->fresh()->schedules()->where('op_type', 'molding_injection')->where('is_interrupted', 0)->first();
        // X 全链结束（各工序均按新口径计算，含固定换模与 5% 预留）
        $expected = $b->copy()->addHours(8)->addMinutes(50)->addSeconds(15)->format('Y-m-d H:i:s');
        $this->assertSame($expected, $aMolding->plan_start->format('Y-m-d H:i:s'), '被打断任务剩余量从插入任务整链结束后开始');
        $this->assertSame(3800, $aMolding->qty);
    }

    public function test_insert_interrupt_does_not_guess_done_qty_from_elapsed_plan(): void
    {
        // A 的计划开始时间已过去 2 小时，但没有实际报工；不能按时间比例猜测产量。
        $startAt = now()->subHours(2)->format('Y-m-d H:i:s');
        $taskA = $this->createTask('P-0001', 3800);
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $this->machine('I-01')->id, 'plan_start' => $startAt]],
        ])->assertOk();

        $taskX = $this->createTask('P-0001', 1900);

        $previewBody = [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $startAt,
            'strategy' => 'interrupt',
            'force_interrupt' => true,
        ];

        $preview = $this->api($this->token)->postJson('/api/schedule/insert', $previewBody);

        $preview->assertOk();
        $interrupted = $preview->json('data.interrupted');
        $this->assertSame(0, $interrupted['done_qty'], '没有实际报工时不得按计划经过时间推算完成量');
        $this->assertSame(3800, $interrupted['remaining_qty']);
    }

    public function test_insert_interrupt_rejects_reported_schedule_even_when_actual_markers_missing(): void
    {
        // 存量数据可能已有报工流水，但排程行的 actual 标记尚未补齐；
        // 仍不能重建原 schedule_id，否则历史流水会失去工序关联。
        $startAt = now()->subHours(2)->format('Y-m-d H:i:s');
        $taskA = $this->createTask('P-0001', 3800);
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $this->machine('I-01')->id, 'plan_start' => $startAt]],
        ])->assertOk();

        $schedule = $taskA->fresh()->schedules()->where('seq', 1)->firstOrFail();
        $adminId = \App\Models\User::where('employee_no', 'admin')->value('id');
        WorkReport::create([
            'task_id' => $taskA->id,
            'schedule_id' => $schedule->id,
            'product_id' => $taskA->product_id,
            'machine_id' => $schedule->machine_id,
            'qty' => 800,
            'defect_qty' => 0,
            'sign' => 1,
            'report_by' => $adminId,
            'worker_id' => null,
            'report_time' => now()->subHour(),
            'source' => WorkReport::SOURCE_LEADER_BATCH,
            'offline_flag' => 0,
        ]);
        WorkReport::create([
            'task_id' => $taskA->id,
            'schedule_id' => $schedule->id,
            'product_id' => $taskA->product_id,
            'machine_id' => $schedule->machine_id,
            'qty' => -200,
            'defect_qty' => 0,
            'sign' => -1,
            'report_by' => $adminId,
            'worker_id' => null,
            'report_time' => now()->subMinutes(30),
            'source' => WorkReport::SOURCE_LEADER_BATCH,
            'offline_flag' => 0,
            'remark' => '存量流水冲减',
        ]);

        $taskX = $this->createTask('P-0001', 1900);
        $previewBody = [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $startAt,
            'strategy' => 'interrupt',
            'force_interrupt' => true,
        ];

        $preview = $this->api($this->token)->postJson('/api/schedule/insert', $previewBody);

        $preview->assertStatus(409)
            ->assertJsonPath('data.errors.0.code', 'cannot_interrupt_reported');
    }

    public function test_insert_confirm_rewrites_only_affected_tasks_and_preserves_report_link(): void
    {
        $b = $this->base();
        $moldingOnly = [[
            'seq' => 1,
            'op_type' => 'molding_injection',
            'op_name' => '注塑成型',
            'qty' => 1900,
        ]];
        $taskA = $this->createTask('P-0001', 1900);
        $taskX = $this->createTask('P-0001', 1900);
        $taskA->update(['route_ops_override' => $moldingOnly]);
        $taskX->update(['route_ops_override' => $moldingOnly]);

        $machine = $this->machine('I-01');
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [[
                'task_id' => $taskA->id,
                'machine_id' => $machine->id,
                'plan_start' => $b->format('Y-m-d H:i:s'),
            ]],
        ])->assertOk();
        $oldSchedule = $taskA->fresh()->schedules()->firstOrFail();
        $adminId = \App\Models\User::where('employee_no', 'admin')->value('id');
        WorkReport::create([
            'task_id' => $taskA->id,
            'schedule_id' => $oldSchedule->id,
            'product_id' => $taskA->product_id,
            'machine_id' => $oldSchedule->machine_id,
            'qty' => 100,
            'defect_qty' => 0,
            'sign' => 1,
            'report_by' => $adminId,
            'worker_id' => null,
            'report_time' => now()->subMinute(),
            'source' => WorkReport::SOURCE_LEADER_BATCH,
            'offline_flag' => 0,
        ]);

        $body = [
            'task_id' => $taskX->id,
            'machine_id' => $machine->id,
            'plan_start' => $b->format('Y-m-d H:i:s'),
            'strategy' => 'wait',
        ];
        $this->api($this->token)->postJson('/api/schedule/insert/confirm', $body)->assertOk();

        $this->assertNotNull(TaskSchedule::find($oldSchedule->id), '无关任务的排程不应被删除重建');
        $this->assertDatabaseHas('work_reports', [
            'task_id' => $taskA->id,
            'schedule_id' => $oldSchedule->id,
            'qty' => 100,
        ]);
    }

    public function test_insert_conflict_with_mold_cascade_across_machines(): void
    {
        $b = $this->base();
        // A: M-001 @I-01 基准日 08:00-18:30（3800 件）
        $taskA = $this->createTask('P-0001', 3800);
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskA->id, 'machine_id' => $this->machine('I-01')->id, 'plan_start' => $b->format('Y-m-d 08:00:00')]],
        ])->assertOk();

        // B: 同模具 M-001 @I-02 后两日 00:00 开始（避开 A 原计划成型，但会与插单后的 A 剩余量重叠）。
        // 注：喷漆落 P-01、效果落 P-02、包装落 P-03（客户确认「资源强对应」），需避开 A 的人工线链
        // （A 3800 件链会跨越次日），确保插入后的模具占用能触发 B 的级联顺延。
        $taskB = $this->createTask('P-0001', 3800);
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $taskB->id, 'machine_id' => $this->machine('I-02')->id, 'plan_start' => $b->copy()->addDays(2)->format('Y-m-d 00:00:00')]],
        ])->assertOk();

        // X: 插基准日 08:00 interrupt A → A 剩余延至次日 13:50+，模具占用延伸 → B 被级联顺延
        $taskX = $this->createTask('P-0001', 3800);
        $previewBody = [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'interrupt',
            'force_interrupt' => true,
        ];

        $preview = $this->api($this->token)->postJson('/api/schedule/insert', $previewBody);

        $preview->assertOk();
        $changed = collect($preview->json('data.changed_tasks'));
        $this->assertNotNull($changed->firstWhere('task_id', $taskB->id), '同模具跨机台任务应被级联顺延');

        $this->api($this->token)->postJson('/api/schedule/insert/confirm', $previewBody)->assertOk();

        // B 成型顺延至 A 剩余结束之后（A 剩余 end = 次日 13:50:30 + 10.5h = 第三日 00:20:30）
        $bMolding = $taskB->fresh()->schedules()->where('op_type', 'molding_injection')->first();
        $aRemainEnd = $taskA->fresh()->schedules()->where('op_type', 'molding_injection')->where('is_interrupted', 0)->first()->plan_end;
        $this->assertGreaterThanOrEqual($aRemainEnd->getTimestamp(), $bMolding->plan_start->getTimestamp(), 'B 应在 A 剩余模具占用结束后开始');
    }

    public function test_insert_interrupt_locked_task_requires_force(): void
    {
        $b = $this->base();
        $taskA = $this->createTask('P-0001', 3800);
        $this->prepareMachineQueue([$taskA]); // 保存即锁定

        $taskX = $this->createTask('P-0001', 1900);

        $baseBody = [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'interrupt',
        ];

        // 无 force → 409
        $rejected = $this->api($this->token)->postJson('/api/schedule/insert', $baseBody);
        $rejected->assertStatus(409);
        $this->assertContains('interrupt_locked', collect($rejected->json('data.errors'))->pluck('code')->all());

        // force_interrupt → 允许（显式确认打断已锁定任务）
        $this->api($this->token)->postJson('/api/schedule/insert', $baseBody + ['force_interrupt' => true])->assertOk();
    }

    public function test_insert_confirm_lock_check_and_rollback_restores(): void
    {
        $b = $this->base();
        $taskA = $this->createTask('P-0001', 1900);
        $this->prepareMachineQueue([$taskA]);

        $taskX = $this->createTask('P-0001', 3800);

        $confirmBody = [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'wait',
        ];

        $confirm = $this->api($this->token)->postJson('/api/schedule/insert/confirm', $confirmBody);

        $confirm->assertOk();
        $versionId = $confirm->json('data.version.id');
        $taskX = $taskX->fresh('product');
        $this->assertSame(
            (int) $taskX->product->default_mold_id,
            (int) $taskX->mold_id,
            '插单确认应把解析出的产品默认模具回写到任务'
        );

        // 已排任务不可重复插单（幂等保护）
        $this->api($this->token)->postJson('/api/schedule/insert/confirm', $confirmBody)->assertStatus(409);

        // 回滚：X 回待排、A 恢复原位
        $rollback = $this->api($this->token)->postJson("/api/schedule/versions/{$versionId}/rollback");
        $rollback->assertOk();

        $taskX = $taskX->fresh();
        $this->assertSame('unscheduled', $taskX->status);
        $this->assertSame(0, $taskX->is_locked);
        $this->assertDatabaseMissing('task_schedules', ['task_id' => $taskX->id]);

        $aMolding = $taskA->fresh()->schedules()->where('op_type', 'molding_injection')->first();
        $this->assertSame($b->format('Y-m-d 08:00:00'), $aMolding->plan_start->format('Y-m-d H:i:s'), '回滚后 A 恢复原排程');

        $this->assertDatabaseHas('schedule_versions', ['id' => $versionId, 'status' => 'rolled_back']);

        // 已回滚版本不可再回滚
        $this->api($this->token)->postJson("/api/schedule/versions/{$versionId}/rollback")->assertStatus(409);
    }

    public function test_orders_affected_endpoint_matches_preview(): void
    {
        $b = $this->base();
        $taskA = $this->createTask('P-0001', 1900);
        $this->prepareMachineQueue([$taskA]);

        $taskX = $this->createTask('P-0001', 3800);

        $query = [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'wait',
        ];

        $preview = $this->api($this->token)->postJson('/api/schedule/insert', $query)->json('data');

        $affected = $this->api($this->token)->getJson('/api/schedule/orders-affected?'.http_build_query($query));

        $affected->assertOk();
        $this->assertSame($preview['affected_orders'], $affected->json('data.affected_orders'), 'GET 影响清单与插单预览一致');
    }

    public function test_insert_confirm_preserves_aps_fields_when_rewriting_shifted_rows(): void
    {
        $b = $this->base();
        $taskA = $this->createTask('P-0001', 1900);
        $taskC = $this->createTask('P-0001', 1900);
        $this->prepareMachineQueue([$taskA, $taskC], 1);

        $forecastStart = $b->copy()->addDay()->addMinutes(15);
        $actualStart = $b->copy()->addDay()->addMinutes(20);
        $cMolding = $taskC->fresh()->schedules()->where('op_type', 'molding_injection')->firstOrFail();
        $cMolding->update([
            'forecast_start' => $forecastStart,
            'forecast_end' => $b->copy()->addDay()->addHours(6),
            'actual_start' => $actualStart,
            'actual_end' => null,
            'is_frozen' => 1,
            'changeover_minutes' => 77,
        ]);

        $taskX = $this->createTask('P-0001', 3800);
        $body = [
            'task_id' => $taskX->id,
            'machine_id' => $this->machine('I-01')->id,
            'plan_start' => $b->format('Y-m-d 08:00:00'),
            'strategy' => 'wait',
        ];

        $this->api($this->token)->postJson('/api/schedule/insert/confirm', $body)->assertOk();

        $shifted = $taskC->fresh()->schedules()->where('op_type', 'molding_injection')->firstOrFail();
        $this->assertSame($forecastStart->format('Y-m-d H:i:s'), $shifted->forecast_start?->format('Y-m-d H:i:s'));
        $this->assertSame(1, (int) $shifted->is_frozen);
        $this->assertSame(77, (int) $shifted->changeover_minutes);
        $this->assertSame($actualStart->format('Y-m-d H:i:s'), $shifted->actual_start?->format('Y-m-d H:i:s'));
    }
}
