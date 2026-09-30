<?php

namespace Tests\Feature\Production;

use App\Models\Machine;
use App\Models\Mold;
use App\Models\MoldLifeLog;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Production\Events\ReportSaved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 报工测试（三期验收 C2~C7）
 * - 台账只增不改（冲减负记录留痕）
 * - 累计不超任务量（over_report_pct 可配置）
 * - 合格/次品口径（计件按合格数）
 * - 当前仅班组长/统计员报工，不区分个人作业人
 * - 报工 → 任务进度/模具寿命联动
 */
class ReportTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /** 建单并排产（默认 P-0001 1900 件；未来时间；dayOffset 错开同机台任务） */
    private function scheduledTask(string $productCode = 'P-0001', int $qty = 1900, int $dayOffset = 0): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '报工测试',
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        $order = Order::findOrFail($response->json('data.id'));
        $task = ProdTask::firstWhere('order_line_id', $order->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$order->id.'/route-confirm')->assertOk();

        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $start = now()->addDays(2 + $dayOffset)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => $start->format('Y-m-d H:i:s')]],
        ])->assertOk();

        return $task->fresh();
    }

    public function test_report_creates_ledger_and_updates_task_progress(): void
    {
        $task = $this->scheduledTask();

        $resp = $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => 100,
            'source' => 'scan',
        ]);

        $resp->assertStatus(201)->assertJsonPath('data.task.done_qty', 100);

        $this->assertDatabaseHas('work_reports', [
            'task_id' => $task->id, 'qty' => 100, 'sign' => 1, 'source' => 'scan',
        ]);
        $this->assertSame(100, $task->fresh()->done_qty, '任务完成量应更新');
        $this->assertSame('in_progress', $task->fresh()->status, '首次报工任务进入生产中');
    }

    public function test_report_time_is_not_limited_by_current_time(): void
    {
        $task = $this->scheduledTask();
        $futureStart = now()->addDay()->setTime(8, 0);
        $futureEnd = $futureStart->copy()->addHours(2);

        $response = $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => 100,
            'report_time' => $futureEnd->format('Y-m-d H:i:s'),
            'start_time' => $futureStart->format('Y-m-d H:i:s'),
            'end_time' => $futureEnd->format('Y-m-d H:i:s'),
            'work_date' => $futureStart->format('Y-m-d'),
        ])->assertStatus(201);

        $response->assertJsonPath('data.report.report_time', $futureEnd->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('work_reports', [
            'task_id' => $task->id,
            'report_time' => $futureEnd->format('Y-m-d H:i:s'),
            'start_time' => $futureStart->format('Y-m-d H:i:s'),
            'end_time' => $futureEnd->format('Y-m-d H:i:s'),
            'work_date' => $futureStart->format('Y-m-d'),
        ]);
    }

    public function test_defect_qty_not_counted_in_good_progress(): void
    {
        $task = $this->scheduledTask();

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => 80,
            'defect_qty' => 10,
        ])->assertStatus(201);

        $this->assertSame(80, $task->fresh()->done_qty, '计件按合格数（次品不计入完成量）');
        $this->assertDatabaseHas('work_reports', ['task_id' => $task->id, 'defect_qty' => 10]);
    }

    public function test_unscheduled_task_cannot_report(): void
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '未排产客户',
            'lines' => [['product_code' => 'P-0001', 'qty' => 100]],
        ])->assertStatus(201);
        $order = Order::findOrFail($response->json('data.id'));
        $task = ProdTask::firstWhere('order_line_id', $order->lines->first()->id);

        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 10])
            ->assertStatus(422)
            ->assertJsonPath('data.error_code', 'task_unscheduled');
        $this->assertDatabaseCount('work_reports', 0);
    }

    public function test_over_report_limit_blocked_with_configured_pct(): void
    {
        $task = $this->scheduledTask(); // qty 1900

        // 默认允许超报 100%（上限 3800）：到 3800 成功，3801 拒绝
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 3800])->assertStatus(201);

        $blocked = $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 1]);
        $blocked->assertStatus(422);
        $this->assertSame('over_report_limit', $blocked->json('data.error_code'));
        $this->assertStringContainsString('允许上限', $blocked->json('message'));

        // 配置收紧为 0（不允许超报）：单次超任务量即拦（错开 6 天，避开首任务班组链——人工线 150/h 链长约 5 天）
        \App\Modules\System\Services\ConfigService::set('schedule.over_report_pct', 0);
        $task2 = $this->scheduledTask('P-0001', 500, 6);
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task2->id, 'qty' => 400])->assertStatus(201);
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task2->id, 'qty' => 101])
            ->assertStatus(422)->assertJsonPath('data.error_code', 'over_report_limit');
    }

    public function test_correction_inserts_negative_record_and_requires_reason(): void
    {
        $task = $this->scheduledTask();

        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 100])->assertStatus(201);

        // 冲减无原因 → 拒绝
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => -30])
            ->assertStatus(422)
            ->assertJsonPath('data.error_code', 'correction_remark_required');

        // 冲减带原因 → 负记录落库（台账只增不改），完成任务量回退
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => -30, 'remark' => '误报更正'])
            ->assertStatus(201);

        $this->assertDatabaseHas('work_reports', ['task_id' => $task->id, 'qty' => -30, 'sign' => -1, 'remark' => '误报更正']);
        $this->assertSame(70, $task->fresh()->done_qty, '冲减后完成量回退');
        $this->assertSame(2, WorkReport::where('task_id', $task->id)->count(), '台账只增不改（3 行中 2 行记录+初始？实为 2 条）');
    }

    public function test_correction_copies_original_work_fields_and_hides_entry_after_full_offset(): void
    {
        $task = $this->scheduledTask();
        $schedule = $task->schedules()->where('seq', 1)->firstOrFail();

        $positiveResponse = $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'qty' => 30,
            'source' => WorkReport::SOURCE_LEADER_BATCH,
        ])->assertStatus(201);
        $positiveId = (int) $positiveResponse->json('data.report.id');
        $positive = WorkReport::findOrFail($positiveId);

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => -30,
            'correction_of_report_id' => $positiveId,
            'remark' => '整笔报工更正',
        ])->assertStatus(201);

        $negative = WorkReport::where('correction_of_report_id', $positiveId)->firstOrFail();
        foreach (['task_id', 'schedule_id', 'product_id', 'machine_id', 'shift_code', 'source'] as $field) {
            $this->assertSame($positive->{$field}, $negative->{$field}, "冲减记录字段 {$field} 应复制原正报工");
        }
        $this->assertSame($positive->work_date?->format('Y-m-d'), $negative->work_date?->format('Y-m-d'));
        $this->assertSame($positive->report_time?->format('Y-m-d H:i:s'), $negative->report_time?->format('Y-m-d H:i:s'));
        $this->assertSame($positive->start_time?->format('Y-m-d H:i:s'), $negative->start_time?->format('Y-m-d H:i:s'));
        $this->assertSame($positive->end_time?->format('Y-m-d H:i:s'), $negative->end_time?->format('Y-m-d H:i:s'));
        $this->assertSame(-30, $negative->qty);

        $rows = $this->api($this->token)
            ->getJson('/api/reports?task_id='.$task->id)
            ->assertOk()
            ->json('data.data');
        $positiveRow = collect($rows)->firstWhere('id', $positiveId);
        $this->assertSame(0, (int) $positiveRow['correctable_qty'], '正报工全部冲减后不应再有冲减入口');

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => -1,
            'correction_of_report_id' => $positiveId,
            'remark' => '重复冲减',
        ])->assertStatus(422)->assertJsonPath('data.error_code', 'correction_exceeds_balance');
    }

    public function test_route_operation_summary_does_not_double_apply_correction_sign(): void
    {
        $task = $this->scheduledTask();
        $schedule = $task->schedules()->where('seq', 1)->firstOrFail();

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'qty' => 100,
        ])->assertStatus(201);
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'qty' => -30,
            'remark' => '工序冲减口径测试',
        ])->assertStatus(201);

        $data = $this->api($this->token)->getJson('/api/orders/'.$task->line->order_id.'/route-ops')
            ->assertOk()
            ->json('data');
        $op = collect($data['tasks'][0]['ops'])->firstWhere('seq', 1);
        $correction = WorkReport::where('task_id', $task->id)->latest('id')->firstOrFail();

        $this->assertSame(70, $op['done_qty']);
        $this->assertSame(3.7, $op['percent']);
        $this->assertSame(-30, $correction->net_qty);
    }

    public function test_report_summary_supports_all_business_dimensions(): void
    {
        $task = $this->scheduledTask();
        $schedule = $task->schedules()->where('seq', 1)->firstOrFail();

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'qty' => 100,
            'start_time' => now()->subHours(2)->format('Y-m-d H:i:s'),
            'end_time' => now()->subHour()->format('Y-m-d H:i:s'),
        ])->assertStatus(201);

        foreach (['detail', 'daily', 'progress', 'delivery', 'resource', 'quality'] as $dimension) {
            $response = $this->api($this->token)
                ->getJson('/api/reports/summary?dimension='.$dimension)
                ->assertOk();

            $response->assertJsonPath('data.dimension', $dimension);
            $this->assertIsArray($response->json('data.items'));
            $this->assertGreaterThanOrEqual(1, $response->json('data.totals.report_count'));
        }
    }

    public function test_task_detail_done_qty_uses_finished_process_only(): void
    {
        $task = $this->scheduledTask();
        $schedules = $task->schedules()->orderBy('seq')->get();
        $this->assertGreaterThan(1, $schedules->count());
        $last = $schedules->last();
        $this->assertNotNull($last);

        // 中间工序做了 100，末工序只做了 40；任务详情应展示成品完成量 40，
        // 不能把两道工序相加成 140。
        foreach ($schedules->slice(0, -1) as $schedule) {
            $this->api($this->token)->postJson('/api/reports', [
                'task_id' => $task->id,
                'schedule_id' => $schedule->id,
                'machine_id' => $schedule->machine_id,
                'qty' => 100,
            ])->assertStatus(201);
        }
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $last->id,
            'machine_id' => $last->machine_id,
            'qty' => 40,
        ])->assertStatus(201);

        $this->api($this->token)->getJson('/api/tasks/'.$task->id)
            ->assertOk()
            ->assertJsonPath('data.report_summary.done_qty', 40);
    }

    public function test_correction_from_allowance_does_not_trigger_over_report_limit(): void
    {
        $task = $this->scheduledTask(); // qty 1900，默认允许上限 3800

        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 3800])
            ->assertStatus(201);

        // 冲减不是新增数量，即使当前已达到超报上限，也应允许更正。
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => -100,
            'remark' => '超报更正',
        ])->assertStatus(201);

        $this->assertSame(3700, (int) $task->fresh()->done_qty);
    }

    public function test_correction_after_process_completion_rolls_back_task_and_schedule_state(): void
    {
        $task = $this->scheduledTask();
        $schedules = $task->schedules()->orderBy('seq')->get();

        // 按工序链逐道报满，最后一道完成后任务与订单进入完成状态。
        foreach ($schedules as $schedule) {
            $this->api($this->token)->postJson('/api/reports', [
                'task_id' => $task->id,
                'schedule_id' => $schedule->id,
                'qty' => $schedule->qty,
            ])->assertStatus(201);
        }

        $task->refresh();
        $last = $schedules->last()->fresh();
        $this->assertSame(ProdTask::STATUS_DONE, $task->status);
        $this->assertSame(TaskSchedule::STATUS_DONE, $last->status);
        $this->assertNotNull($last->actual_end);

        // 冲减最后一道工序后，不能继续保留 done/actual_end。
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $last->id,
            'qty' => -$last->qty,
            'remark' => '完工误报更正',
        ])->assertStatus(201);

        $task->refresh();
        $last->refresh();
        $this->assertSame(0, (int) $task->done_qty);
        $this->assertSame(ProdTask::STATUS_SCHEDULED, $task->status);
        $this->assertSame(TaskSchedule::STATUS_PENDING, $last->status);
        $this->assertNull($last->actual_end);
        $this->assertDatabaseHas('orders', ['id' => $task->line->order_id, 'status' => 'scheduled']);
        $this->assertNull($task->line->order->fresh()->completed_at, '冲减回退订单状态时应清除完结时间');
    }

    public function test_new_report_rejects_interrupted_or_completed_schedule(): void
    {
        $task = $this->scheduledTask();
        $schedule = $task->schedules()->where('seq', 1)->firstOrFail();

        $schedule->update(['is_interrupted' => 1]);
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'qty' => 1,
        ])->assertStatus(422)->assertJsonPath('data.error_code', 'schedule_interrupted');

        $schedule->update([
            'is_interrupted' => 0,
            'status' => TaskSchedule::STATUS_DONE,
        ]);
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'qty' => 1,
        ])->assertStatus(422)->assertJsonPath('data.error_code', 'schedule_completed');

        // 历史流水仍可用负数冲减，不破坏只增不改台账规则。
        WorkReport::create([
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'product_id' => $task->product_id,
            'machine_id' => $schedule->machine_id,
            'qty' => 1,
            'defect_qty' => 0,
            'sign' => 1,
            'report_by' => 1,
            'report_time' => now()->subMinute(),
            'work_date' => now()->toDateString(),
            'source' => WorkReport::SOURCE_CLICK,
        ]);
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $schedule->id,
            'machine_id' => $schedule->machine_id,
            'qty' => -1,
            'remark' => '历史工序误报更正',
        ])->assertStatus(201);
    }

    public function test_report_rolls_back_when_linkage_listener_fails(): void
    {
        $task = $this->scheduledTask();
        Event::listen(ReportSaved::class, static function (): void {
            throw new \RuntimeException('模拟联动异常');
        });

        $response = $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'qty' => 100,
        ]);

        $response->assertStatus(500);
        $response->assertJsonPath('data.error_code', 'report_linkage_failed');
        $this->assertStringContainsString('模拟联动异常', $response->json('message'));
        $this->assertDatabaseMissing('work_reports', ['task_id' => $task->id]);
        $this->assertSame(0, (int) $task->fresh()->done_qty);
    }

    public function test_only_report_operators_can_report_without_worker_attribution(): void
    {
        $task = $this->scheduledTask();

        // 班组长不再选择实际作业人，直接按操作账号填报
        $leader = $this->loginToken('L001');
        $this->api($leader)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 50, 'source' => 'leader_batch',
        ])->assertStatus(201);

        $report = WorkReport::latest('id')->first();
        $this->assertNull($report->worker_id, '新报工不再记录个人作业人');
        $this->assertSame('L001', $report->operator->employee_no, '操作人为班组长');

        // 即使历史客户端带 worker_id，也不再接受个人归属
        $this->api($leader)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 10, 'worker_id' => \App\Models\User::where('employee_no', 'W001')->first()->id,
        ])->assertStatus(422)->assertJsonPath('data.error_code', 'worker_attribution_disabled');

        // 普通工人不再具备报工权限
        $worker = $this->loginToken('W001');
        $this->api($worker)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 10,
        ])->assertStatus(403);

        // 统计员可统一填报，且仍保留操作人审计
        $statistician = $this->loginToken('T001');
        $this->api($statistician)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 10, 'source' => 'leader_batch',
        ])->assertStatus(201);
        $this->assertNull(WorkReport::latest('id')->first()->worker_id);
    }

    public function test_duplicate_verify_detects_same_submission(): void
    {
        $task = $this->scheduledTask();

        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 100])->assertStatus(201);

        // 同 task+数量+作业人 5 分钟内 → 重复
        $this->api($this->token)->postJson('/api/reports/verify', ['task_id' => $task->id, 'qty' => 100])
            ->assertOk()->assertJsonPath('data.duplicated', true);

        // 不同数量 → 不重复
        $this->api($this->token)->postJson('/api/reports/verify', ['task_id' => $task->id, 'qty' => 101])
            ->assertOk()->assertJsonPath('data.duplicated', false);
    }

    public function test_report_drives_mold_life_and_task_done(): void
    {
        $task = $this->scheduledTask(); // 1900 件，M-001 模穴 4
        $mold = Mold::where('code', 'M-001')->firstOrFail();
        $beforeOpenings = (int) $mold->opened_count;

        // 报 800 合格 → 开模 200 次（800 ÷ 4）
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 800])->assertStatus(201);

        $this->assertDatabaseHas('mold_life_logs', [
            'mold_id' => $mold->id, 'task_id' => $task->id, 'opening_count' => 200,
        ]);
        $this->assertSame($beforeOpenings + 200, (int) $mold->fresh()->opened_count, '模具累计开模次数联动');

        // 冲减 100 → 开模次数应回退（负向留档）
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => -100, 'remark' => '返工更正'])
            ->assertStatus(201);
        $this->assertSame($beforeOpenings + 200 - 25, (int) $mold->fresh()->opened_count, '冲减回退 25 次开模（100÷4）');

        // 报满 → done + 订单聚合 produced
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 1900, 'remark' => '一次报满',
        ])->assertStatus(201);
        $this->assertSame('done', $task->fresh()->status, '完成任务');
        $this->assertDatabaseHas('orders', ['id' => $task->line->order_id, 'status' => 'produced']);
        $this->assertNotNull($task->line->order->fresh()->completed_at, '报工驱动订单完成时应记录完结时间');
    }

    public function test_correction_touching_stocked_auto_offsets_stock_in(): void
    {
        // QA P1 防回归：冲减触及已入库量 → 自动红字冲减入库单，保持 完成量 ≥ 已入库量
        $task = $this->scheduledTask(); // 1900 件
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 100])->assertStatus(201);

        \Illuminate\Support\Facades\Http::fake(); // 有 fake 时 K3 推送不发出真实请求
        $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 80])
            ->assertStatus(201);
        $this->assertSame(80, $task->fresh()->stocked_qty);

        // 冲减 60 → 完成量 40 < 已入库 80 → 联动回冲 40（负入库单），已入库变 40
        $resp = $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => -60, 'remark' => 'QA 联动防回归',
        ]);
        $resp->assertStatus(201);

        $task = $task->fresh();
        $this->assertSame(40, $task->done_qty, '冲减后完成量 40');
        $this->assertSame(40, $task->stocked_qty, '已入库联动回冲至 40（≥ 完成量，无负库存矛盾）');

        // 红字冲减入库单留痕：Σstock_ins = 80 + (-40) = 40 = Σreports
        $this->assertDatabaseHas('stock_ins', ['task_id' => $task->id, 'qty' => -40]);
        $insSum = (int) \App\Models\StockIn::where('task_id', $task->id)->sum('qty');
        $reportSum = (int) \App\Models\WorkReport::where('task_id', $task->id)->sum('qty');
        $this->assertSame($reportSum, $insSum, '对账一致：Σstock_ins = Σreports');

        // 可入库量为 0（不出现负值）
        $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id)
            ->assertOk()->assertJsonPath('data.available_qty', 0);

        // 冲减到负完成量 → 422 correction_below_zero
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => -100, 'remark' => '超冲测试'])
            ->assertStatus(422)->assertJsonPath('data.error_code', 'correction_below_zero');
    }

    public function test_final_process_correction_uses_finished_qty_not_all_process_qty_for_stock_offset(): void
    {
        $task = $this->scheduledTask();
        $schedules = $task->schedules()->orderBy('seq')->get();

        // 中间工序累计量很大，末工序只有 100；入库必须只看末工序的 100。
        foreach ($schedules as $index => $schedule) {
            $qty = $index === $schedules->count() - 1 ? 100 : ($index === 0 ? 500 : 100);
            $this->api($this->token)->postJson('/api/reports', [
                'task_id' => $task->id,
                'schedule_id' => $schedule->id,
                'machine_id' => $schedule->machine_id,
                'qty' => $qty,
            ])->assertStatus(201);
        }

        \Illuminate\Support\Facades\Http::fake();
        $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 80])
            ->assertStatus(201);

        // 末工序冲减 60 后成品净量为 40，应自动回冲库存 40；
        // 若错误汇总全部工序，会把中间量当成成品而漏回冲。
        $last = $schedules->last();
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $last->id,
            'machine_id' => $last->machine_id,
            'qty' => -60,
            'remark' => '末工序库存口径测试',
        ])->assertStatus(201);

        $this->assertSame(40, (int) $task->fresh()->stocked_qty);
        $this->assertDatabaseHas('stock_ins', ['task_id' => $task->id, 'qty' => -40]);
        $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id)
            ->assertOk()
            ->assertJsonPath('data.reported_qty', 40)
            ->assertJsonPath('data.available_qty', 0);
    }

    public function test_offline_report_flag_logged(): void
    {
        $task = $this->scheduledTask();

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 30, 'source' => 'offline', 'offline_flag' => 1,
        ])->assertStatus(201);

        $this->assertDatabaseHas('work_reports', ['task_id' => $task->id, 'offline_flag' => 1, 'source' => 'offline']);
    }

    public function test_report_ledger_index_filters(): void
    {
        $task = $this->scheduledTask();
        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => 60])->assertStatus(201);

        $list = $this->api($this->token)->getJson('/api/reports?task_id='.$task->id);
        $list->assertOk()->assertJsonPath('data.total', 1);
        $this->assertSame(60, $list->json('data.data.0.qty'));

        $history = $this->api($this->token)->getJson("/api/tasks/{$task->id}/report-history");
        $history->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_report_ledger_carries_schedule_and_machine_name(): void
    {
        $task = $this->scheduledTask();
        $first = $task->schedules()->orderBy('seq')->first();

        // 工序报工：台账带出工序名 + 机台中文名（前端只显示 name，不显示 code）
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'schedule_id' => $first->id, 'qty' => 30,
        ])->assertStatus(201);

        $list = $this->api($this->token)->getJson('/api/reports?task_id='.$task->id.'&per_page=20');
        $list->assertOk();
        $rows = collect($list->json('data.data'));
        $opRow = $rows->first(fn ($r) => isset($r['schedule']) && ($r['schedule']['id'] ?? null) === $first->id);
        $this->assertNotNull($opRow, '工序报工应出现在台账');
        $this->assertNotEmpty($opRow['schedule']['op_name'] ?? '', '台账应带出工序名');
        $this->assertNotEmpty($opRow['machine']['name'] ?? '', '机台/产线应显示中文名称');
    }

    public function test_next_process_cannot_exceed_previous_process_done(): void
    {
        $task = $this->scheduledTask('P-0001', 1000);
        $schedules = $task->schedules()->orderBy('seq')->get();
        $this->assertGreaterThanOrEqual(2, $schedules->count(), '种子工艺至少 2 道工序');

        $first = $schedules->first();
        $second = $schedules->get(1);

        // 第一道工序报 100 → 通过
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'schedule_id' => $first->id, 'qty' => 100,
        ])->assertStatus(201);

        // 第二道报 101 > 上道 100 → 422 process_chain_exceed
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'schedule_id' => $second->id, 'qty' => 101,
        ])->assertStatus(422)->assertJsonPath('data.error_code', 'process_chain_exceed');

        // 第二道报 100（= 上道完工）→ 通过
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'schedule_id' => $second->id, 'qty' => 100,
        ])->assertStatus(201);
    }

    public function test_next_process_sums_split_previous_schedule_segments(): void
    {
        $task = $this->scheduledTask('P-0001', 1000);
        $schedules = $task->schedules()->orderBy('seq')->get();
        $first = $schedules->first();
        $second = $schedules->get(1);

        // 模拟插单后的同工序拆段：历史段 60 + 当前剩余段 40。
        $first->update(['is_interrupted' => 1]);
        $remaining = $first->replicate();
        $remaining->is_interrupted = 0;
        $remaining->status = TaskSchedule::STATUS_PENDING;
        $remaining->done_qty = 0;
        $remaining->plan_start = $first->plan_end;
        $remaining->plan_end = $first->plan_end->copy()->addHours(2);
        $remaining->save();

        foreach ([[$first, 60], [$remaining, 40]] as [$segment, $qty]) {
            WorkReport::create([
                'task_id' => $task->id,
                'schedule_id' => $segment->id,
                'product_id' => $task->product_id,
                'machine_id' => $segment->machine_id,
                'qty' => $qty,
                'defect_qty' => 0,
                'sign' => 1,
                'report_by' => 1,
                'report_time' => now()->subMinute(),
                'work_date' => now()->toDateString(),
                'source' => WorkReport::SOURCE_LEADER_BATCH,
            ]);
        }

        // 前序两段合计 100，后道一次报 100 应通过，而不是按任一单段数量拒绝。
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $second->id,
            'qty' => 100,
        ])->assertStatus(201);
    }

    public function test_next_process_rejects_when_split_current_segments_exceed_previous(): void
    {
        $task = $this->scheduledTask('P-0001', 1000);
        $schedules = $task->schedules()->orderBy('seq')->get();
        $first = $schedules->first();
        $second = $schedules->get(1);

        WorkReport::create([
            'task_id' => $task->id,
            'schedule_id' => $first->id,
            'product_id' => $task->product_id,
            'machine_id' => $first->machine_id,
            'qty' => 1000,
            'defect_qty' => 0,
            'sign' => 1,
            'report_by' => 1,
            'report_time' => now()->subMinute(),
            'work_date' => now()->toDateString(),
            'source' => WorkReport::SOURCE_LEADER_BATCH,
        ]);

        // 当前工序拆成历史段 600 + 剩余段已有 300；本次再报 200 后总量应为 1100，必须拒绝。
        $second->update(['is_interrupted' => 1]);
        $remaining = $second->replicate();
        $remaining->is_interrupted = 0;
        $remaining->status = TaskSchedule::STATUS_PENDING;
        $remaining->qty = 400;
        $remaining->done_qty = 0;
        $remaining->plan_start = $second->plan_end;
        $remaining->plan_end = $second->plan_end->copy()->addHours(2);
        $remaining->save();

        foreach ([[$second, 600], [$remaining, 300]] as [$segment, $qty]) {
            WorkReport::create([
                'task_id' => $task->id,
                'schedule_id' => $segment->id,
                'product_id' => $task->product_id,
                'machine_id' => $segment->machine_id,
                'qty' => $qty,
                'defect_qty' => 0,
                'sign' => 1,
                'report_by' => 1,
                'report_time' => now()->subMinute(),
                'work_date' => now()->toDateString(),
                'source' => WorkReport::SOURCE_LEADER_BATCH,
            ]);
        }

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $remaining->id,
            'qty' => 200,
        ])->assertStatus(422)->assertJsonPath('data.error_code', 'process_chain_exceed');
    }

    public function test_task_progress_sums_split_final_schedule_segments(): void
    {
        $task = $this->scheduledTask('P-0001', 1000);
        $schedules = $task->schedules()->orderBy('seq')->get();
        $final = $schedules->last();

        // 先让前三道工序具备足够前序量，再模拟最后一道工序被拆成 100+900 两段。
        foreach ($schedules->slice(0, $schedules->count() - 1) as $schedule) {
            WorkReport::create([
                'task_id' => $task->id,
                'schedule_id' => $schedule->id,
                'product_id' => $task->product_id,
                'machine_id' => $schedule->machine_id,
                'qty' => 1000,
                'defect_qty' => 0,
                'sign' => 1,
                'report_by' => 1,
                'report_time' => now()->subMinute(),
                'work_date' => now()->toDateString(),
                'source' => WorkReport::SOURCE_LEADER_BATCH,
            ]);
        }
        $final->update(['is_interrupted' => 1]);
        $remaining = $final->replicate();
        $remaining->is_interrupted = 0;
        $remaining->status = TaskSchedule::STATUS_PENDING;
        $remaining->qty = 900;
        $remaining->done_qty = 0;
        $remaining->plan_start = $final->plan_end;
        $remaining->plan_end = $final->plan_end->copy()->addHours(2);
        $remaining->save();
        WorkReport::create([
            'task_id' => $task->id,
            'schedule_id' => $final->id,
            'product_id' => $task->product_id,
            'machine_id' => $final->machine_id,
            'qty' => 100,
            'defect_qty' => 0,
            'sign' => 1,
            'report_by' => 1,
            'report_time' => now()->subMinute(),
            'work_date' => now()->toDateString(),
            'source' => WorkReport::SOURCE_LEADER_BATCH,
        ]);

        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id,
            'schedule_id' => $remaining->id,
            'qty' => 900,
        ])->assertStatus(201);

        $this->assertSame(1000, (int) $task->fresh()->done_qty);
        $this->assertSame(ProdTask::STATUS_DONE, $task->fresh()->status);
    }

    public function test_manual_report_machine_override_saved(): void
    {
        $task = $this->scheduledTask('P-0001', 500);
        $first = $task->schedules()->orderBy('seq')->first();

        // 手动报工可指定机台：默认排程机台之外可修改，落库 machine_id 采用指定值
        $i01 = Machine::where('code', 'I-01')->firstOrFail();
        $resp = $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'schedule_id' => $first->id, 'qty' => 50, 'machine_id' => $i01->id,
        ])->assertStatus(201);

        $this->assertSame($i01->id, (int) $resp->json('data.report.machine_id'), '报工落库机台为手动指定机台');
    }
}
