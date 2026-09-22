<?php

namespace Tests\Feature\Qa;

use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\Workshop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 二期排产引擎 · QA 独立验收边界用例（fresh eyes，不与工程师用例重复）
 *
 * 覆盖验收补充项：防重 / 换模连续性 / 模具跨机台互斥 / 停用机台 / 回滚回池
 *                / 关闭订单防排 / 交期过期标识 / Excel 全错行 / AI 降级
 */
class QaScheduleAcceptanceTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    /** 建单（默认 P-0001 1900 件 = 注塑 5h + 换模 30min = 330min）并返回生成的任务 */
    private function createTask(string $productCode = 'P-0001', int $qty = 1900, array $lineExtra = []): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => 'QA验收客户',
            'lines' => [['product_code' => $productCode, 'qty' => $qty] + $lineExtra],
        ])->assertStatus(201);

        $order = Order::findOrFail($response->json('data.id'));
        // 工艺确认：订单创建默认 route_confirm，确认后进入待排产（订单-工艺-排产流程）
        $this->api($this->token)->postJson('/api/orders/'.$order->id.'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', $order->lines->first()->id);
    }

    private function machine(string $code): Machine
    {
        return Machine::where('code', $code)->firstOrFail();
    }

    /** 保存排程并返回响应 */
    private function saveSchedule(array $items, bool $force = false)
    {
        return $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => $items,
            'force' => $force,
        ]);
    }

    // ------------------------------------------------------------------
    // 1) 同一任务二次排产（防重）
    // ------------------------------------------------------------------

    public function test_duplicate_task_in_single_batch_must_not_duplicate_chain(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $m = $this->machine('I-01');

        $resp = $this->saveSchedule([
            ['task_id' => $task->id, 'machine_id' => $m->id, 'plan_start' => '2026-12-01 08:00:00'],
            ['task_id' => $task->id, 'machine_id' => $m->id, 'plan_start' => '2026-12-01 08:00:00'],
        ]);

        $chainCount = TaskSchedule::where('task_id', $task->id)->count();
        $routeOpCount = 4; // 成型/喷漆/效果/包装

        // 请求层拒绝（4xx）或去重后仅一条工序链均可接受；禁止落两条重复链
        $this->assertTrue($resp->status() >= 400 || $chainCount === $routeOpCount,
            "同一任务在一次请求中重复出现应被拒绝或去重，实际状态 {$resp->status()}，排程行数 {$chainCount}");
    }

    public function test_scheduled_task_cannot_be_rescheduled_while_locked(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $m = $this->machine('I-01');

        $this->saveSchedule([
            ['task_id' => $task->id, 'machine_id' => $m->id, 'plan_start' => '2026-12-01 08:00:00'],
        ])->assertOk();

        $this->assertSame(1, $task->fresh()->is_locked, '保存即锁定（B7）');

        // 锁定后二次排产 → 409 task_locked
        $this->saveSchedule([
            ['task_id' => $task->id, 'machine_id' => $m->id, 'plan_start' => '2026-12-02 08:00:00'],
        ])->assertStatus(409)->assertJsonPath('data.errors.0.code', 'task_locked');
    }

    public function test_unlock_then_reschedule_replaces_old_chain_cleanly(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $m1 = $this->machine('I-01');
        $m2 = $this->machine('I-02');

        $this->saveSchedule([
            ['task_id' => $task->id, 'machine_id' => $m1->id, 'plan_start' => '2026-12-01 08:00:00'],
        ])->assertOk();

        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id], 'reason' => 'QA 重排验收',
        ])->assertOk();

        // 解锁后重排到另一机台
        $this->saveSchedule([
            ['task_id' => $task->id, 'machine_id' => $m2->id, 'plan_start' => '2026-12-05 08:00:00'],
        ])->assertOk();

        // 旧排程被替换而非叠加：仍只有一条工序链，且全部落在新机台
        $rows = TaskSchedule::where('task_id', $task->id)->get();
        $this->assertSame(4, $rows->count(), '重排后不得残留旧排程（防重）');
        $this->assertSame($m2->id, $rows->first()->machine_id, '成型工序应落在新机台');
    }

    // ------------------------------------------------------------------
    // 2) 换模后紧邻任务时间连续性（中间不留错误空隙）
    // ------------------------------------------------------------------

    public function test_consecutive_tasks_on_same_machine_no_overlap_no_gap(): void
    {
        $taskA = $this->createTask('P-0001', 1900);
        $taskB = $this->createTask('P-0001', 1900);
        $m = $this->machine('I-01');

        $this->saveSchedule([
            ['task_id' => $taskA->id, 'machine_id' => $m->id, 'plan_start' => '2026-10-10 08:00:00'],
        ])->assertOk();

        $aEnd = TaskSchedule::where('task_id', $taskA->id)->max('plan_end');

        // B 从 A 全链结束后至少间隔 30 分钟开始
        $bStart = \Illuminate\Support\Carbon::parse($aEnd)->addMinutes(30);
        $this->saveSchedule([
            ['task_id' => $taskB->id, 'machine_id' => $m->id, 'plan_start' => $bStart->format('Y-m-d H:i:s')],
        ])->assertOk();

        $aMold = TaskSchedule::where('task_id', $taskA->id)->where('op_type', 'molding_injection')->first();
        $bMold = TaskSchedule::where('task_id', $taskB->id)->where('op_type', 'molding_injection')->first();

        // 换模时间计入任务时长，并增加 5% 预留：（1900/400h + 0.5h）× 1.05 = 19845s
        $this->assertSame(19845, CarbonParse($aMold->plan_start, $aMold->plan_end), 'A 注塑时长应含固定 30min 换模与 5% 预留');
        $this->assertSame(19845, CarbonParse($bMold->plan_start, $bMold->plan_end), 'B 注塑时长应含固定 30min 换模与 5% 预留');

        // 同一机台上：B 成型开始 = A 全链结束 + 30 分钟安全间隔
        $this->assertSame(
            $bStart->format('Y-m-d H:i:s'),
            \Illuminate\Support\Carbon::parse($bMold->plan_start)->format('Y-m-d H:i:s'),
            '同机台任务应保留 30 分钟切换安全间隔'
        );
        $this->assertTrue($bMold->plan_start >= $aMold->plan_end, '同机台任务不得重叠');
    }

    // ------------------------------------------------------------------
    // 3) 模具同时被两任务占用（跨机台互斥）—— 后端双验证
    // ------------------------------------------------------------------

    public function test_same_mold_across_machines_overlap_conflict_and_force(): void
    {
        $taskA = $this->createTask('P-0001', 1900); // 默认模具 M-001
        $taskB = $this->createTask('P-0001', 1900);
        $m1 = $this->machine('I-01');
        $m2 = $this->machine('I-02');

        // M-001 兼容 I-01/I-02，两任务跨机台时间重叠 → 模具互斥
        $resp = $this->saveSchedule([
            ['task_id' => $taskA->id, 'machine_id' => $m1->id, 'plan_start' => '2026-11-01 08:00:00'],
            ['task_id' => $taskB->id, 'machine_id' => $m2->id, 'plan_start' => '2026-11-01 09:00:00'],
        ]);

        $resp->assertStatus(409);

        $codes = collect($resp->json('data.errors'))->pluck('code');
        $this->assertContains('resource_conflict', $codes->all(), '应返回资源冲突清单');
        $this->assertStringContainsString('mold:', json_encode($resp->json('data.errors')), '冲突资源应指向模具');

        // force 显式确认 → 允许但标记
        $forced = $this->saveSchedule([
            ['task_id' => $taskA->id, 'machine_id' => $m1->id, 'plan_start' => '2026-11-01 08:00:00'],
            ['task_id' => $taskB->id, 'machine_id' => $m2->id, 'plan_start' => '2026-11-01 09:00:00'],
        ], true);
        $forced->assertOk();

        $this->assertDatabaseHas('task_schedules', [
            'task_id' => $taskB->id, 'is_overlap' => 1,
        ]);
        $this->assertStringContainsString('强制', (string) $forced->json('message'), '强制排入应显式确认并提示');
    }

    /** 机台更新完整载荷（MachineRequest 必填） */
    private function machinePayload(array $extra = []): array
    {
        return [
            'code' => 'I-03', 'name' => '注塑机 3 号', 'type' => 'injection',
            'workshop_id' => Workshop::where('code', 'injection')->firstOrFail()->id,
            'capacity_per_hour' => 380, 'mold_change_min' => 30,
            'shift_mode' => 'two_shift', 'status' => 'running',
        ] + $extra;
    }

    // ------------------------------------------------------------------
    // 4) 删除/停用机台后已排程任务的处理
    // ------------------------------------------------------------------

    public function test_deactivated_machine_rejects_new_schedule(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $m = $this->machine('I-03');

        // 停用机台
        $this->api($this->token)->putJson("/api/plm/machines/{$m->id}",
            $this->machinePayload(['is_active' => 0])
        )->assertOk();

        $this->saveSchedule([
            ['task_id' => $task->id, 'machine_id' => $m->id, 'plan_start' => '2026-12-01 08:00:00'],
        ])->assertStatus(409)->assertJsonPath('data.errors.0.code', 'machine_inactive');
    }

    public function test_deactivate_machine_with_scheduled_tasks_must_not_leave_orphan_schedule(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $m = $this->machine('I-03');

        $this->saveSchedule([
            ['task_id' => $task->id, 'machine_id' => $m->id, 'plan_start' => '2026-12-01 08:00:00'],
        ])->assertOk();

        // 停用含已排程任务的机台 → 应提示或拒绝，不留悬空排程
        $resp = $this->api($this->token)->putJson("/api/plm/machines/{$m->id}",
            $this->machinePayload(['is_active' => 0])
        );

        $this->assertTrue(
            $resp->status() === 409
            || (str_contains((string) $resp->json('message'), '排程') || str_contains((string) $resp->json('message'), '排产')),
            '停用存在排程的机台应给出明确提示或拒绝，实际状态 '.$resp->status().' 消息：'.$resp->json('message')
        );
    }

    // ------------------------------------------------------------------
    // 5) 版本回滚到"插单后"版本：新增任务回待排池
    // ------------------------------------------------------------------

    public function test_rollback_insert_version_returns_task_to_pool(): void
    {
        $taskA = $this->createTask('P-0001', 1900);
        $taskX = $this->createTask('P-0001', 3800);
        $m = $this->machine('I-01');

        $this->saveSchedule([
            ['task_id' => $taskA->id, 'machine_id' => $m->id, 'plan_start' => '2026-12-01 08:00:00'],
        ])->assertOk();

        // 插单 X（wait 策略）→ 生成插单版本
        $confirm = $this->api($this->token)->postJson('/api/schedule/insert/confirm', [
            'task_id' => $taskX->id,
            'machine_id' => $m->id,
            'plan_start' => '2026-12-01 08:00:00',
            'strategy' => 'wait',
        ])->assertOk();
        $versionId = $confirm->json('data.version.id');

        // 回滚
        $this->api($this->token)->postJson("/api/schedule/versions/{$versionId}/rollback")->assertOk();

        // X 回到待排池：状态 unscheduled、解锁、无排程、出现在任务池
        $x = $taskX->fresh();
        $this->assertSame('unscheduled', $x->status);
        $this->assertSame(0, $x->is_locked);
        $this->assertDatabaseMissing('task_schedules', ['task_id' => $taskX->id]);

        $pool = $this->api($this->token)->getJson('/api/schedule/task-pool')->assertOk()->json('data.items');
        $this->assertContains($taskX->id, collect($pool)->pluck('id')->all(), '回滚后新增任务应回到待排任务池');

        // A 恢复且锁定保持
        $this->assertSame(1, $taskA->fresh()->is_locked);
        $this->assertNotContains($taskA->id, collect($pool)->pluck('id')->all());
    }

    // ------------------------------------------------------------------
    // 6) 订单已关闭/任务完成后的排产限制
    // ------------------------------------------------------------------

    public function test_done_task_of_delivered_order_cannot_be_rescheduled(): void
    {
        $task = $this->createTask('P-0001', 1900);
        $m = $this->machine('I-01');

        $this->saveSchedule([
            ['task_id' => $task->id, 'machine_id' => $m->id, 'plan_start' => '2026-12-01 08:00:00'],
        ])->assertOk();

        // 模拟任务已完成（订单聚合为 produced，视为已关闭）
        $orderId = $task->line->order_id;
        $task->update(['status' => ProdTask::STATUS_DONE]);
        Order::whereKey($orderId)->update(['status' => Order::STATUS_PRODUCED]);

        // 已完成任务不允许重排（含"订单已关闭"语义）：解锁后仍应被拒
        $this->api($this->token)->postJson('/api/schedule/unlock', [
            'task_ids' => [$task->id], 'reason' => 'QA 验收',
        ])->assertOk();

        $this->saveSchedule([
            ['task_id' => $task->id, 'machine_id' => $m->id, 'plan_start' => '2026-12-02 08:00:00'],
        ])->assertStatus(409)->assertJsonPath('data.errors.0.code', 'task_not_editable');
    }

    public function test_overdue_delivery_task_flagged_in_pool_and_schedulable(): void
    {
        // 交期已过期（模拟历史交期）
        $task = $this->createTask('P-0001', 1900, ['delivery_date' => '2020-01-01']);

        $pool = $this->api($this->token)->getJson('/api/schedule/task-pool')->assertOk()->json('data.items');
        $item = collect($pool)->firstWhere('id', $task->id);

        $this->assertNotNull($item, '过期交期任务应出现在任务池');
        $this->assertTrue($item['is_overdue'], '任务池应标记 is_overdue（前端红框兜底）');

        // 排产允许（业务可取），但任务仍被标记（甘特侧前端标红依赖）
        $m = $this->machine('I-01');
        $this->saveSchedule([
            ['task_id' => $task->id, 'machine_id' => $m->id, 'plan_start' => '2026-12-01 08:00:00'],
        ])->assertOk();
        $this->assertSame('scheduled', $task->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 7) Excel 导入边界
    // ------------------------------------------------------------------

    public function test_excel_all_invalid_rows_422_with_zero_import(): void
    {
        $csv = "产品编码,数量,交期,客户,优先级,备注\n"
            ."NO-SUCH-01,1000,,客户甲,3,\n"
            ."NO-SUCH-02,-5,,客户乙,3,\n"
            ."NO-SUCH-03,0,,客户丙,3,\n";
        $path = tempnam(sys_get_temp_dir(), 'qa_').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$csv);

        try {
            $resp = $this->api($this->token)->post('/api/orders/import-excel', [
                'file' => new \Illuminate\Http\UploadedFile($path, 'bad.csv', 'text/csv', null, true),
            ]);
        } finally {
            @unlink($path);
        }

        $resp->assertStatus(422);
        $this->assertSame(0, $resp->json('data.imported_count'), '全错行应零入库');
        $this->assertSame(3, $resp->json('data.skipped_count'));
        $this->assertCount(3, $resp->json('data.error_rows'), '应完整返回全部错误行');
        $this->assertSame(0, Order::count(), '不得创建任何订单');
    }

    public function test_excel_mixed_rows_207_partial_import_with_error_rows(): void
    {
        $csv = "产品编码,数量,交期,客户,优先级,备注\n"
            ."P-0001,1000,2026-12-31,客户甲,3,\n"
            ."BAD-CODE,2000,2026-12-31,客户乙,3,\n"
            ."P-0003,800,2026-12-31,客户丙,3,\n";
        $path = tempnam(sys_get_temp_dir(), 'qa_').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$csv);

        try {
            $resp = $this->api($this->token)->post('/api/orders/import-excel', [
                'file' => new \Illuminate\Http\UploadedFile($path, 'mixed.csv', 'text/csv', null, true),
            ]);
        } finally {
            @unlink($path);
        }

        $resp->assertStatus(207);
        $this->assertSame(2, $resp->json('data.imported_count'), '合法行应导入');
        $this->assertSame(1, $resp->json('data.skipped_count'), '错误行应跳过');
        $this->assertSame('BAD-CODE', $resp->json('data.error_rows.0.product_code'), '应返回错误行编码');
        // 数据库落库日期为上海时区 Y-m-d；API 输出为 UTC ISO8601（前端需按 ISO 解析）
        $this->assertDatabaseHas('orders', [
            'id' => $resp->json('data.order.id'),
            'delivery_date' => '2026-12-31 00:00:00',
        ]);

        // 合法行已全部生成生产任务（2 行）
        $lineIds = \App\Models\OrderLine::where('order_id', $resp->json('data.order.id'))->pluck('id');
        $this->assertSame(2, ProdTask::whereIn('order_line_id', $lineIds)->count(), '合法行应生成 2 个生产任务');
    }

    // ------------------------------------------------------------------
    // 8) AI 服务不可用 → 503 降级手工（B6 铁保底）
    // ------------------------------------------------------------------

    public function test_auto_schedule_service_down_returns_503_fallback(): void
    {
        config(['ai.service_url' => 'http://127.0.0.1:1']); // 不可达端口

        $task = $this->createTask('P-0001', 1900);

        $resp = $this->api($this->token)->postJson('/api/schedule/auto', [
            'task_ids' => [$task->id],
        ]);

        $resp->assertStatus(503);
        $this->assertStringContainsString('降级', (string) $resp->json('message'), '应明确提示降级手工排产');
    }
}

/** 计算两个时间字符串之间的秒数差 */
function CarbonParse(string $start, string $end): int
{
    return (int) \Illuminate\Support\Carbon::parse($end)->getTimestamp()
        - (int) \Illuminate\Support\Carbon::parse($start)->getTimestamp();
}
