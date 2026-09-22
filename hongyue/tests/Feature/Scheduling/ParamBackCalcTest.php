<?php

namespace Tests\Feature\Scheduling;

use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * APS参数反推建议测试（基础数据准确性治理：先默认值跑起来，报工数据反推修正）
 *
 * - 产能反推：实际速率 = Σ报工合格 ÷ 实际时长，按机台聚合对照标准产能
 * - 换模反推：同机台相邻排程换模间隔聚合为模具对建议
 * - 无实际数据时空清单（默认值阶段系统正常工作）
 */
class ParamBackCalcTest extends TestCase
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
            'customer_name' => '参数反推测试',
            'lines' => [['product_code' => $productCode, 'qty' => $qty]],
        ])->assertStatus(201);

        $orderId = $response->json('data.id');
        $this->api($this->token)->postJson('/api/orders/'.$orderId.'/route-confirm')->assertOk();

        return ProdTask::firstWhere('order_line_id', Order::find($orderId)->lines->first()->id);
    }

    private function saveOnI01(ProdTask $task, string $start): void
    {
        $machine = Machine::where('code', 'I-01')->first();
        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => $start]],
        ])->assertOk();
    }

    public function test_capacity_backcalc_from_actuals(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-01 06:00:00'));

        $task = $this->createTask('P-0001', 400);
        $this->saveOnI01($task, '2025-08-01 08:00:00');
        $molding = TaskSchedule::where('task_id', $task->id)->where('op_type', 'molding_injection')->firstOrFail();

        // 报工 400 件后，手工回填受控的实际区间（2 小时完成 400 件 → 实际 200/时，标准 400/时）
        $this->api($this->token)->postJson('/api/reports', [
            'task_id' => $task->id, 'qty' => 400, 'schedule_id' => $molding->id,
        ])->assertStatus(201);
        $molding->update(['actual_start' => '2025-08-01 08:00:00', 'actual_end' => '2025-08-01 10:00:00']);

        $r = $this->api($this->token)->getJson('/api/schedule/param-backcalc')->assertOk();

        $row = collect($r->json('data.capacity'))->firstWhere('machine_code', 'I-01');
        $this->assertNotNull($row);
        $this->assertSame(200.0, (float) $row['actual_avg']);
        $this->assertSame(1, (int) $row['samples']);
        $this->assertSame(400.0, (float) $row['current_capacity']);
        $this->assertSame(-50.0, (float) $row['deviation_pct']);
    }

    public function test_changeover_backcalc_from_actual_gaps(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2025-08-01 06:00:00'));

        $taskA = $this->createTask('P-0001', 400); // 默认模具 M-001
        $this->saveOnI01($taskA, '2025-08-01 08:00:00');
        $taskB = $this->createTask('P-0003', 400); // 默认模具 M-003
        $this->saveOnI01($taskB, '2025-08-02 08:00:00');

        $a = TaskSchedule::where('task_id', $taskA->id)->where('op_type', 'molding_injection')->firstOrFail();
        $b = TaskSchedule::where('task_id', $taskB->id)->where('op_type', 'molding_injection')->firstOrFail();
        // 前序 10:00 结束，后序 10:50 开始 → 实际换模 50 分钟样本
        $a->update(['actual_start' => '2025-08-01 08:00:00', 'actual_end' => '2025-08-01 10:00:00']);
        $b->update(['actual_start' => '2025-08-01 10:50:00', 'actual_end' => '2025-08-01 12:00:00']);

        $r = $this->api($this->token)->getJson('/api/schedule/param-backcalc')->assertOk();

        $pair = collect($r->json('data.changeover'))->firstWhere('from_mold_code', 'M-001');
        $this->assertNotNull($pair);
        $this->assertSame('M-003', $pair['to_mold_code']);
        $this->assertSame(50.0, (float) $pair['actual_avg_min']);
        $this->assertSame(1, (int) $pair['samples']);
        $this->assertFalse($pair['in_matrix']); // 矩阵尚无记录，等待工程师确认后维护
    }

    public function test_empty_actuals_yields_empty_suggestions(): void
    {
        // 无实际数据阶段：清单为空，系统按默认值正常运行（不产生噪音建议）
        $r = $this->api($this->token)->getJson('/api/schedule/param-backcalc')->assertOk();
        $this->assertSame([], $r->json('data.capacity'));
        $this->assertSame([], $r->json('data.changeover'));
    }
}
