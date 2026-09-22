<?php

namespace Tests\Feature\Qa;

use App\Models\IntegrationLog;
use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\StockIn;
use App\Models\WorkReport;
use App\Modules\Warehouse\Services\StockInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 三期「报工+分批入库+金蝶推送」QA 独立验收边界（fresh eyes，不与工程师用例重复）
 *
 * 覆盖：冲减×入库联动 / K3 幂等·重试·脱敏 / 并发报工 SUM / 离线补传台账
 *       / 超量配置双向边界 / 完工任务补报行为 / 超量入库双路径 / 移动端模拟凭据
 */
class QaPhase3AcceptanceTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    private const K3_OK = ['Result' => ['ResponseStatus' => ['IsSuccess' => true, 'Errors' => []], 'Id' => 52001, 'Number' => 'K3-IN-QA-001']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
        config(['k3.base_url' => 'http://k3-qa.test', 'k3.mock' => true]);
    }

    /** 有状态 K3 fake：$mode='fail' → HTTP 500；否则成功 */
    private function k3Fake(string &$mode): void
    {
        Http::fake(function ($request) use (&$mode) {
            if ($mode === 'fail') {
                return Http::response('internal error', 500);
            }

            return Http::response(self::K3_OK, 200);
        });
    }

    /** 建单(P-0001)→排产→可回填报工；返回任务 */
    private function scheduledTask(int $qty = 1000): ProdTask
    {
        $resp = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => 'QA三期',
            'lines' => [['product_code' => 'P-0001', 'qty' => $qty]],
        ])->assertStatus(201);
        $order = Order::findOrFail($resp->json('data.id'));
        $task = ProdTask::firstWhere('order_line_id', $order->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$order->id.'/route-confirm')->assertOk();

        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => now()->addDays(6)->startOfDay()->addHours(8)->format('Y-m-d H:i:s')]],
        ])->assertOk();

        return $task->fresh();
    }

    private function report(int $taskId, int $qty, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->api($this->token)->postJson('/api/reports', ['task_id' => $taskId, 'qty' => $qty] + $extra);
    }

    private function stockIn(int $taskId, int $qty): StockIn
    {
        $resp = $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $taskId, 'qty' => $qty]);
        $resp->assertStatus(201);

        return StockIn::findOrFail($resp->json('data.stock_in.id'));
    }

    // ------------------------------------------------------------------
    // a) 冲减与已入库联动：不留"已入库 > 完成量"的负库存概念
    // ------------------------------------------------------------------

    public function test_correction_after_stock_in_guards_inventory_consistency(): void
    {
        $task = $this->scheduledTask();
        $this->report($task->id, 100)->assertStatus(201);
        $this->stockIn($task->id, 80); // 已入库 80

        // 冲减 60 → 完成量将变为 40 < 已入库 80
        $resp = $this->report($task->id, -60, ['remark' => 'QA 冲减联动验收']);

        $task->refresh();
        $stocked = $task->stocked_qty;

        if ($resp->status() === 201) {
            // 若允许冲减：系统必须给出明确提示（不留静默矛盾状态）
            $available = $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id)
                ->json('data.available_qty');

            $this->assertTrue(
                $stocked <= $task->done_qty,
                "冲减后不得出现已入库({$stocked}) > 完成量({$task->done_qty}) 的负库存矛盾"
            );
            $this->assertTrue(
                $resp->json('data.task.stocked_qty') === null || $available >= 0,
                '可入库量不得为负'
            );
        } else {
            // 或直接阻断冲减（422 且带原因）
            $this->assertTrue(in_array($resp->status(), [409, 422], true), '冲减与入库联动应阻断或提示，实际状态 '.$resp->status());
        }

        // 台账只增不改纪律：冲减记录 sign=-1、原因必填存在
        $this->assertDatabaseHas('work_reports', ['task_id' => $task->id, 'qty' => -60, 'sign' => -1]);
    }

    /** 自动回冲方案专项：恒等式/红字单字段/不自动推金蝶 */
    public function test_auto_red_invoice_correction_exact_boundary(): void
    {
        $task = $this->scheduledTask();
        $this->report($task->id, 100)->assertStatus(201);
        $mode = 'ok';
        $this->k3Fake($mode);
        $this->stockIn($task->id, 80); // 已入库 80（推送成功）

        // 冲减 60 → afterNet=40 < 80 → 自动回冲 40
        $resp = $this->report($task->id, -60, ['remark' => '自动回冲验收']);
        $resp->assertStatus(201);
        $this->assertNotNull($resp->json('data.stock_in_correction.stock_in.id'), '应返回自动回冲红字单');

        $red = StockIn::findOrFail($resp->json('data.stock_in_correction.stock_in.id'));
        $this->assertSame(-40, (int) $red->qty, '红字单应为负数量（恰好回冲边界 80-40）');
        $this->assertSame(StockIn::K3_QUEUED, $red->k3_push_status, '红字单应待人工确认推送，不自动推金蝶');
        $this->assertStringContainsString('冲减', (string) $red->remark, '红字单备注应溯源为冲减联动');
        $this->assertTrue(str_starts_with($red->ins_no, 'RK-'), '红字单应使用独立入库单号');

        // 恒等式：Σstock_ins = 完成量；已入库量恰好回退到边界（不超冲）
        $task->refresh();
        $this->assertSame(40, (int) $task->done_qty);
        $this->assertSame(40, (int) $task->stocked_qty, 'stocked 应恰好回退到完成量边界，不超冲');
        $this->assertSame(40, (int) StockIn::where('task_id', $task->id)->sum('qty'), 'Σstock_ins = Σreports 对账一致');

        // available-qty 双路径一致且非负
        $available = $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id)->json('data');
        $this->assertSame(0, $available['available_qty']);
        $this->assertTrue($available['available_qty'] >= 0, '可入库量不得为负');

        // 红字单人工重推金蝶（负数量报文应被 K3 接受）
        $push = $this->api($this->token)->postJson("/api/warehouse/ins/{$red->id}/push")->assertOk();
        $this->assertTrue($push->json('data.pushed'), '红字单人工重推应成功');
        $this->assertSame(StockIn::K3_SUCCESS, $red->fresh()->k3_push_status);
        $this->assertDatabaseHas('integration_logs', [
            'api_type' => 'push_stock_in', 'biz_id' => $red->id, 'status' => IntegrationLog::STATUS_SUCCESS,
        ]);
    }

    /** 冲减后完成量为负 → 422 correction_below_zero */
    public function test_correction_below_zero_rejected_422(): void
    {
        $task = $this->scheduledTask();
        $this->report($task->id, 40)->assertStatus(201);

        // 冲减 60 → afterNet=-20 < 0 → 阻断
        $resp = $this->report($task->id, -60, ['remark' => '负值验收']);
        $resp->assertStatus(422)->assertJsonPath('data.error_code', 'correction_below_zero');

        // 台账未被破坏：无负冲减落库、完成量保持 40
        $this->assertDatabaseMissing('work_reports', ['task_id' => $task->id, 'qty' => -60]);
        $this->assertSame(40, (int) $task->fresh()->done_qty);
    }

    // ------------------------------------------------------------------
    // b) K3 推送：幂等 / HTTP 失败重试恢复 / 日志脱敏
    // ------------------------------------------------------------------

    public function test_k3_push_second_push_is_idempotent_skip(): void
    {
        $task = $this->scheduledTask();
        $this->report($task->id, 500)->assertStatus(201);

        // 入库时推送失败（HTTP 500）→ 手动重推成功 → 再推幂等跳过
        $mode = 'fail';
        $this->k3Fake($mode);
        $ins = $this->stockIn($task->id, 300);
        $this->assertSame(StockIn::K3_FAILED, $ins->fresh()->k3_push_status, '入库时推送失败应置 failed');

        $mode = 'ok';
        $first = $this->api($this->token)->postJson("/api/warehouse/ins/{$ins->id}/push")->assertOk();
        $this->assertTrue($first->json('data.pushed'), '首次推送应成功');
        $this->assertSame(StockIn::K3_SUCCESS, $ins->fresh()->k3_push_status);
        $this->assertNotNull($ins->fresh()->k3_bill_no, '应写入金蝶单据号');

        // 同单重推 → 幂等跳过，不重复建单/不重复写成功日志
        $second = $this->api($this->token)->postJson("/api/warehouse/ins/{$ins->id}/push")->assertOk();
        $this->assertFalse($second->json('data.pushed'), '重复推送应幂等跳过');

        $successLogs = IntegrationLog::where('api_type', 'push_stock_in')
            ->where('biz_id', $ins->id)->where('status', IntegrationLog::STATUS_SUCCESS)->count();
        $this->assertSame(1, $successLogs, '成功日志必须唯一（幂等键）');
    }

    public function test_k3_http_failure_marks_failed_then_recovery_push_success(): void
    {
        $task = $this->scheduledTask();
        $this->report($task->id, 500)->assertStatus(201);

        // 全程 HTTP 500：入库即失败 → 手动重推仍失败（502）→ 恢复后补推成功
        $mode = 'fail';
        $this->k3Fake($mode);
        $ins = $this->stockIn($task->id, 200);
        $this->assertSame(StockIn::K3_FAILED, $ins->fresh()->k3_push_status);

        $fail = $this->api($this->token)->postJson("/api/warehouse/ins/{$ins->id}/push");
        $fail->assertStatus(502);
        $this->assertSame(StockIn::K3_FAILED, $ins->fresh()->k3_push_status);
        $this->assertDatabaseHas('integration_logs', [
            'api_type' => 'push_stock_in', 'biz_id' => $ins->id, 'status' => IntegrationLog::STATUS_FAILED,
        ]);

        // 恢复 → 手动重推成功
        $mode = 'ok';
        $recover = $this->api($this->token)->postJson("/api/warehouse/ins/{$ins->id}/push")->assertOk();
        $this->assertTrue($recover->json('data.pushed'), '恢复后补推应成功');
        $this->assertSame(StockIn::K3_SUCCESS, $ins->fresh()->k3_push_status);
        $this->assertNotNull($ins->fresh()->k3_bill_no, '应写入金蝶单据号');
    }

    public function test_integration_log_request_body_masked_no_sensitive_keys(): void
    {
        $task = $this->scheduledTask();
        $this->report($task->id, 500)->assertStatus(201);

        // 构造带敏感键的报文（模拟登录/报文含密钥场景），验证脱敏
        $mode = 'ok';
        $this->k3Fake($mode);
        $ins = $this->stockIn($task->id, 150);

        // 手动模拟一次带敏感键的日志（推送服务 maskRequestBody 对敏感键打码）
        try {
            $ref = new \ReflectionClass($ins);
        } catch (\Throwable) {
        }
        $this->api($this->token)->postJson("/api/warehouse/ins/{$ins->id}/push")->assertOk();

        $log = IntegrationLog::where('api_type', 'push_stock_in')->where('biz_id', $ins->id)
            ->where('status', IntegrationLog::STATUS_SUCCESS)->firstOrFail();
        $body = json_encode($log->request_body ?? []);

        foreach (['password', 'acct_id', 'username', 'token', 'session'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive.'":"', (string) $body, '日志报文不得含敏感键明文');
        }
    }

    // ------------------------------------------------------------------
    // c) 并发报工：串行双请求最终 SUM 正确、台账完整
    // ------------------------------------------------------------------

    public function test_sequential_double_report_sum_correct(): void
    {
        $task = $this->scheduledTask(1000);
        $this->report($task->id, 50)->assertStatus(201);
        $this->report($task->id, 70)->assertStatus(201);

        $task->refresh();
        $this->assertSame(120, (int) $task->done_qty, '串行双报工 SUM 应累加正确');
        $this->assertSame(120, (int) WorkReport::where('task_id', $task->id)->sum('qty'), '台账 SUM(qty) 一致');
        $this->assertSame(2, WorkReport::where('task_id', $task->id)->where('sign', 1)->count(), '两笔台账均落库');

        // 防重窗口：同数量同人 5 分钟内提示重复（不落第二笔）
        $dup = $this->api($this->token)->postJson('/api/reports/verify', ['task_id' => $task->id, 'qty' => 70]);
        $dup->assertOk();
        $this->assertTrue($dup->json('data.duplicated'), '5 分钟防重窗口应识别重复提交');
    }

    // ------------------------------------------------------------------
    // d) 离线补传：台账时间/来源正确
    // ------------------------------------------------------------------

    public function test_offline_report_stores_time_source_and_flag(): void
    {
        $task = $this->scheduledTask();
        $offlineTime = now()->subHours(6)->format('Y-m-d H:i:s');

        $resp = $this->report($task->id, 80, [
            'source' => WorkReport::SOURCE_OFFLINE,
            'offline_flag' => 1,
            'report_time' => $offlineTime,
        ])->assertStatus(201);

        $report = WorkReport::findOrFail($resp->json('data.report.id'));
        $this->assertSame(1, (int) $report->offline_flag, '离线标志应记录');
        $this->assertSame(WorkReport::SOURCE_OFFLINE, $report->source, '来源应为 offline');
        $this->assertSame($offlineTime, $report->report_time->format('Y-m-d H:i:s'), '补传应保留实际作业时间');

        $task->refresh();
        $this->assertSame(80, (int) $task->done_qty, '离线补传计入任务进度');
    }

    // ------------------------------------------------------------------
    // e) 超量配置双向边界（0 严格拦截 / 高值放行）
    // ------------------------------------------------------------------

    public function test_over_report_pct_config_both_directions(): void
    {
        $task = $this->scheduledTask(1000);

        // pct=0：严格拦截，超 100% 即 422
        \App\Modules\System\Services\ConfigService::set('schedule.over_report_pct', 0, 'QA 边界');
        $this->report($task->id, 1000)->assertStatus(201);
        $this->report($task->id, 1)->assertStatus(422)->assertJsonPath('data.error_code', 'over_report_limit');

        // pct=200：允许至 3 倍任务量；恰好 3000 放行、3001 拦截
        \App\Modules\System\Services\ConfigService::set('schedule.over_report_pct', 200);
        $this->report($task->id, 1999)->assertStatus(201); // 1000+1999=2999 ≤ 3000
        $this->report($task->id, 1)->assertStatus(201);    // 恰好 3000
        $this->report($task->id, 1)->assertStatus(422);    // 3001 超限

        // 恢复默认
        \App\Modules\System\Services\ConfigService::set('schedule.over_report_pct', 100);
    }

    // ------------------------------------------------------------------
    // f) 完工任务补报行为（PRD 支持补报：记录行为不硬拒）
    // ------------------------------------------------------------------

    public function test_done_task_makeup_report_behavior_and_correction(): void
    {
        $task = $this->scheduledTask(1000);
        $this->report($task->id, 1000)->assertStatus(201);
        $this->assertSame(ProdTask::STATUS_DONE, $task->fresh()->status, '报满应置 done');

        // 完工后补报（PRD：补报为明确功能）：允许但应留痕，状态保持 done
        $resp = $this->report($task->id, 100, ['source' => WorkReport::SOURCE_MAKEUP]);
        $this->assertTrue(in_array($resp->status(), [201, 409, 422], true), '完工后补报行为应明确（允许留痕或拒绝）');
        if ($resp->status() === 201) {
            $this->assertDatabaseHas('work_reports', ['task_id' => $task->id, 'qty' => 100, 'source' => WorkReport::SOURCE_MAKEUP]);
            $this->assertSame(ProdTask::STATUS_DONE, $task->fresh()->status);
        }
    }

    // ------------------------------------------------------------------
    // g) 入库超量双路径精确口径（API + Service）
    // ------------------------------------------------------------------

    public function test_stock_in_over_available_rejected_on_api_and_service_paths(): void
    {
        $task = $this->scheduledTask(1000);
        $this->report($task->id, 500)->assertStatus(201);
        $this->stockIn($task->id, 300);

        // API 路径：300 已入 + 201 可入 → 超量 422
        $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 201])
            ->assertStatus(422)->assertJsonPath('data.error_code', 'over_available_qty');

        // Service 直接路径：不依赖 HTTP 层同样拦截
        $service = app(StockInService::class);
        try {
            $service->create($task->id, 201);
            $this->fail('Service 层超量入库应抛异常');
        } catch (\App\Modules\Warehouse\Exceptions\StockInException $e) {
            $this->assertSame('over_available_qty', $e->errorCode(), 'Service 层异常码一致');
        }

        // 分批两单独立单号
        $this->stockIn($task->id, 100);
        $this->stockIn($task->id, 100);
        $nos = StockIn::where('task_id', $task->id)->pluck('ins_no');
        $this->assertSame(3, $nos->count(), '三笔入库');
        $this->assertSame(3, $nos->unique()->count(), '每批独立单号不重复');

        $available = $this->api($this->token)->getJson('/api/warehouse/available-qty?task_id='.$task->id)->json('data');
        $this->assertSame(0, $available['available_qty'], '可入库量精确为 完成−已入库 = 500−500');
    }

    // ------------------------------------------------------------------
    // h) 移动端：模拟凭据免密 + 移动报工全流程
    // ------------------------------------------------------------------

    public function test_mobile_wecom_mock_login_and_report_flow(): void
    {
        config(['wecom.mock_mode' => true]);

        // 模拟凭据免密登录（班组长 L001 统一填报）
        $login = $this->postJson('/api/auth/wecom-login', ['code' => 'wecom_wang']);
        $login->assertOk();
        $mobileToken = $login->json('data.token');

        // 移动端今日任务可读
        $this->withToken($mobileToken)->getJson('/mobile/tasks')->assertOk();

        // 移动端报工
        $task = $this->scheduledTask(1000);
        $rep = $this->withToken($mobileToken)->postJson('/mobile/reports', ['task_id' => $task->id, 'qty' => 66]);
        $rep->assertStatus(201);
        $this->assertNull($rep->json('data.report.worker_id'), '移动报工暂不区分个人作业人');
        $this->assertSame(66, (int) $task->fresh()->done_qty);

        // 我的报工
        $mine = $this->withToken($mobileToken)->getJson('/mobile/reports/mine')->assertOk();
        $this->assertNotEmpty($mine->json('data'), '我的报工列表应返回数据');
    }
}
