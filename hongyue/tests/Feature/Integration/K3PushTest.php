<?php

namespace Tests\Feature\Integration;

use App\Models\IntegrationLog;
use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\StockIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 金蝶生产入库单推送测试（三期验收 C9/C10）
 * - 幂等：成功记录唯一，重推跳过
 * - 失败重试：HTTP 失败 → failed/retry 留痕；恢复后手动重推成功
 * - 适配器契约：FMaterialId/FRealQty/FSrcBillNo/FStockId/FDate/FBillTypeId/FNote
 * - 集成日志全留痕（请求/响应/状态/重试次数）
 */
class K3PushTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
        config(['k3.base_url' => 'http://k3-mock.test']);
    }

    private function successResponse(string $billNo = 'K3-IN-ABC-001'): array
    {
        return [
            'Result' => [
                'ResponseStatus' => ['IsSuccess' => true, 'Errors' => []],
                'Id' => 52001,
                'Number' => $billNo,
            ],
        ];
    }

    /** 建单 → 排产 → 报工 500 → 返回任务 */
    private function reportedTask(int $reportQty = 500): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => 'K3推送测试',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1000]],
        ])->assertStatus(201);
        $order = Order::findOrFail($response->json('data.id'));
        $task = ProdTask::firstWhere('order_line_id', $order->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$order->id.'/route-confirm')->assertOk();

        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $start = now()->addDays(6)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => $start->format('Y-m-d H:i:s')]],
        ])->assertOk();

        $this->api($this->token)->postJson('/api/reports', ['task_id' => $task->id, 'qty' => $reportQty])->assertStatus(201);

        return $task->fresh();
    }

    private function createStockIn(ProdTask $task, int $qty = 200): StockIn
    {
        Http::fake(['k3-mock.test/*' => Http::response($this->successResponse(), 200)]);

        $resp = $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => $qty]);
        $resp->assertStatus(201);

        return StockIn::findOrFail($resp->json('data.stock_in.id'));
    }

    public function test_push_success_writes_receipt_and_log(): void
    {
        $task = $this->reportedTask();
        $stockIn = $this->createStockIn($task);

        $this->assertSame('success', $stockIn->fresh()->k3_push_status);
        $this->assertSame('K3-IN-ABC-001', $stockIn->fresh()->k3_bill_no);
        $this->assertSame('pushed', $stockIn->fresh()->ins_status);

        $log = IntegrationLog::where('api_type', 'push_stock_in')->where('biz_id', $stockIn->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('success', $log->status);
        $this->assertSame('k3', $log->target);
        $this->assertSame('out', $log->direction);

        // 报文留痕含金蝶契约字段
        $requestBody = $log->request_body;
        $entity = $requestBody['model']['FEntity'][0] ?? [];
        $this->assertSame('P-0001', data_get($entity, 'FMaterialId.FNumber'));
        $this->assertEqualsWithDelta(200.0, (float) data_get($entity, 'FRealQty'), 0.001, 'FRealQty 应为 200');
        $this->assertSame($task->line->order->order_no, data_get($entity, 'FSrcBillNo'));
        $this->assertStringContainsString('RK-', (string) data_get($requestBody, 'model.FNote'));
    }

    public function test_push_idempotent_skip_on_resubmit(): void
    {
        $task = $this->reportedTask();
        $stockIn = $this->createStockIn($task);

        $this->assertSame('success', $stockIn->fresh()->k3_push_status);

        // 手动重推：幂等跳过（不新增 success 日志、不重复调用）
        Http::fake(['k3-mock.test/*' => Http::response($this->successResponse(), 200)]);
        $resp = $this->api($this->token)->postJson("/api/warehouse/ins/{$stockIn->id}/push");
        $resp->assertOk()->assertJsonPath('data.pushed', false);

        $this->assertSame(1, IntegrationLog::where('api_type', 'push_stock_in')->where('biz_id', $stockIn->id)->where('status', 'success')->count(), '幂等键唯一：成功记录仅 1 条');
    }

    public function test_push_failure_retries_and_recovers_manually(): void
    {
        $task = $this->reportedTask();

        // 金蝶 500（第一次推送失败）→ 恢复（手动重推成功）：Http::fake 为追加匹配，用 sequence 控制顺序
        Http::fake([
            'k3-mock.test/*' => Http::sequence()
                ->push('boom', 500)
                ->push($this->successResponse('K3-IN-SECOND-002'), 200),
        ]);

        $resp = $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 200]);
        $resp->assertStatus(201); // 入库业务不阻断

        $stockIn = StockIn::latest('id')->firstOrFail();
        $this->assertSame('failed', $stockIn->fresh()->k3_push_status);

        $failLog = IntegrationLog::where('api_type', 'push_stock_in')->where('biz_id', $stockIn->id)->first();
        $this->assertNotNull($failLog);
        $this->assertContains($failLog->status, ['failed', 'retry']);
        $this->assertNotEmpty($failLog->error_msg);

        // 服务恢复 → 手动重推成功（sequence 第二个响应）
        $this->api($this->token)->postJson("/api/warehouse/ins/{$stockIn->id}/push")
            ->assertOk()->assertJsonPath('data.pushed', true);

        $stockIn = $stockIn->fresh();
        $this->assertSame('success', $stockIn->k3_push_status);
        $this->assertSame('K3-IN-SECOND-002', $stockIn->k3_bill_no);
        $this->assertSame('pushed', $stockIn->ins_status);

        $this->assertSame(1, IntegrationLog::where('api_type', 'push_stock_in')->where('biz_id', $stockIn->id)->where('status', 'success')->count());
        $this->assertDatabaseHas('integration_logs', [
            'api_type' => 'push_stock_in', 'biz_id' => $stockIn->id, 'status' => 'failed',
        ]);
    }

    public function test_k3_business_failure_marks_failed(): void
    {
        $task = $this->reportedTask();

        // 金蝶业务失败（ResponseStatus.IsSuccess=false）
        Http::fake(['k3-mock.test/*' => Http::response([
            'Result' => ['ResponseStatus' => ['IsSuccess' => false, 'Errors' => [['Message' => '物料不存在']]]],
        ], 200)]);

        $this->api($this->token)->postJson('/api/warehouse/ins', ['task_id' => $task->id, 'qty' => 100])->assertStatus(201);

        $stockIn = StockIn::latest('id')->firstOrFail();
        $this->assertSame('failed', $stockIn->fresh()->k3_push_status);

        $log = IntegrationLog::where('api_type', 'push_stock_in')->where('biz_id', $stockIn->id)->first();
        $this->assertStringContainsString('物料不存在', (string) $log->error_msg);
    }

    public function test_integration_log_index_filters(): void
    {
        $task = $this->reportedTask();
        $this->createStockIn($task);

        $list = $this->api($this->token)->getJson('/api/system/integration-logs?api_type=push_stock_in&status=success');
        $list->assertOk()->assertJsonPath('data.total', 1);
        $this->assertSame('push_stock_in', $list->json('data.data.0.api_type'));

        $empty = $this->api($this->token)->getJson('/api/system/integration-logs?status=failed');
        $empty->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_adapter_field_mapping_contract(): void
    {
        $task = $this->reportedTask(300);
        $stockIn = new StockIn([
            'ins_no' => 'RK-CONTRACT-001',
            'task_id' => $task->id,
            'product_id' => $task->product_id,
            'qty' => 120,
        ]);
        $stockIn->task = $task;
        $stockIn->task->line = $task->line;

        $payload = (new \App\Modules\Integration\Services\K3StockInAdapter())->adapt($stockIn);

        $this->assertSame('STK_MISCELLANEOUS', $payload['formid']);
        $entity = $payload['model']['FEntity'][0];
        $this->assertSame('P-0001', data_get($entity, 'FMaterialId.FNumber'));
        $this->assertSame(120.0, data_get($entity, 'FRealQty'));
        $this->assertSame($task->line->order->order_no, data_get($entity, 'FSrcBillNo'));
        $this->assertNotNull(data_get($payload, 'model.FStockId.FNumber'));
        $this->assertNotNull(data_get($payload, 'model.FBillTypeID.FNumber'));
        $this->assertNotNull(data_get($payload, 'model.FDate'));
        $this->assertStringContainsString('RK-CONTRACT-001', (string) data_get($payload, 'model.FNote'));
    }
}