<?php

namespace Tests\Feature\Dashboard;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProdTask;
use App\Models\Product;
use App\Models\RedLightClose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 红灯看板 · 四源规则引擎测试（四期验收 D1）
 *
 * ① 未排产（无排程且交期临近/到期）
 * ② 未报工（超过计划起点 N 小时无报工，N 可配）
 * ③ 逾期（交期 < now 未完工）
 * ④ 落后 >10%（deviation_pct）
 * 关闭：写 red_light_closes + audit_logs，重算跳过。
 */
class RedLightTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    private function makeTask(array $overrides = []): ProdTask
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        $order = Order::create([
            'order_no' => 'SO-TEST-'.uniqid(),
            'src' => Order::SRC_MANUAL,
            'customer_name' => '红灯测试客户',
            'status' => Order::STATUS_PENDING,
            'priority' => 3,
            'delivery_date' => now()->addDays(5),
        ]);
        $line = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1000,
            'delivery_date' => now()->addDays(5),
            'priority' => 3,
            'status' => OrderLine::STATUS_PENDING,
            'done_qty' => 0,
            'mold_id' => $product->default_mold_id,
            'line_no' => 1,
        ]);

        return ProdTask::create(array_merge([
            'task_no' => 'TSK-RL-'.uniqid(),
            'order_line_id' => $line->id,
            'product_id' => $product->id,
            'route_id' => $product->default_route_id,
            'mold_id' => $product->default_mold_id,
            'qty' => 1000,
            'done_qty' => 0,
            'status' => ProdTask::STATUS_UNSCHEDULED,
            'priority' => 3,
            'delivery_date' => now()->addDays(5)->toDateString(),
            'deviation_pct' => 0,
            'plan_start' => null,
            'plan_end' => null,
            'is_locked' => 0,
            'source' => 'manual',
        ], $overrides));
    }

    public function test_unscheduled_due_soon_triggers_red_light(): void
    {
        $task = $this->makeTask(['delivery_date' => now()->addDays(2)->toDateString()]);

        $resp = $this->api($this->token)->getJson('/api/dashboard/red-lights');
        $resp->assertOk();

        $items = collect($resp->json('data.items'));
        $this->assertTrue($items->contains(fn ($r) => $r['source'] === 'unscheduled' && $r['task_id'] === (int) $task->id), '未排产且交期临近任务应标红');
        $this->assertSame(1, $resp->json('data.open_count'));

        // 责任人应聚合到计划员
        $group = collect($resp->json('data.groups'))->first(fn ($g) => in_array($task->id, array_column($g['items'], 'task_id'), true));
        $this->assertNotNull($group);
        $this->assertSame('P001', \App\Models\User::find($group['owner_id'])->employee_no, '未排产红灯责任人应为计划员');
    }

    public function test_no_report_after_plan_start_hours(): void
    {
        config(['dashboard.no_report_hours' => 3]);

        $task = $this->makeTask([
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'plan_start' => now()->subHours(5)->toDateTimeString(),
            'plan_end' => now()->addDays(2)->toDateTimeString(),
            'done_qty' => 0,
        ]);

        $resp = $this->api($this->token)->getJson('/api/dashboard/red-lights');
        $resp->assertOk();

        $items = collect($resp->json('data.items'));
        $this->assertTrue($items->contains(fn ($r) => $r['source'] === 'no_report' && $r['task_id'] === (int) $task->id), '计划开始超 N 小时无报工应标红');
    }

    public function test_no_report_skipped_when_report_exists(): void
    {
        config(['dashboard.no_report_hours' => 3]);

        $task = $this->makeTask([
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'plan_start' => now()->subHours(5)->toDateTimeString(),
            'done_qty' => 100,
        ]);

        \App\Models\WorkReport::create([
            'task_id' => $task->id,
            'product_id' => $task->product_id,
            'qty' => 100,
            'defect_qty' => 0,
            'sign' => 1,
            'report_by' => 1,
            'worker_id' => null,
            'report_time' => now()->subHour(),
            'source' => 'scan',
            'offline_flag' => 0,
            'remark' => null,
        ]);

        $items = collect($this->api($this->token)->getJson('/api/dashboard/red-lights')->json('data.items'));
        $this->assertFalse($items->contains(fn ($r) => $r['source'] === 'no_report' && $r['task_id'] === (int) $task->id), '有报工记录不应触发未报工红灯');
    }

    public function test_overdue_delivery_before_now(): void
    {
        $task = $this->makeTask([
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'delivery_date' => now()->subDays(2)->toDateString(),
        ]);

        $items = collect($this->api($this->token)->getJson('/api/dashboard/red-lights')->json('data.items'));
        $this->assertTrue($items->contains(fn ($r) => $r['source'] === 'overdue' && $r['task_id'] === (int) $task->id), '交期已过且未完工应标红逾期');
    }

    public function test_behind_deviation_over_threshold(): void
    {
        config(['dashboard.deviation_pct' => 10]);

        $task = $this->makeTask([
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'deviation_pct' => 15,
        ]);

        $items = collect($this->api($this->token)->getJson('/api/dashboard/red-lights')->json('data.items'));
        $this->assertTrue($items->contains(fn ($r) => $r['source'] === 'behind' && $r['task_id'] === (int) $task->id), '落后 >10% 应标红');

        // 阈值可配：调高后不再触发（fresh=1 绕过 10s 缓存强制重算）
        config(['dashboard.deviation_pct' => 20]);
        $items2 = collect($this->api($this->token)->getJson('/api/dashboard/red-lights?fresh=1')->json('data.items'));
        $this->assertFalse($items2->contains(fn ($r) => $r['source'] === 'behind' && $r['task_id'] === (int) $task->id), '落后未超新阈值不应标红');
    }

    public function test_close_writes_audit_and_skips_recompute(): void
    {
        $task = $this->makeTask(['delivery_date' => now()->addDays(1)->toDateString()]);

        $items = $this->api($this->token)->getJson('/api/dashboard/red-lights')->json('data.items');
        $light = collect($items)->first(fn ($r) => $r['source'] === 'unscheduled' && $r['task_id'] === (int) $task->id);
        $this->assertNotNull($light, '前置：应存在未排产红灯');

        // 关闭（解码 id = task_id*10 + 1）
        $this->api($this->token)->postJson("/api/dashboard/red-lights/{$light['id']}/close", [
            'reason' => '已安排今日排产',
        ])->assertOk();

        $this->assertDatabaseHas('red_light_closes', ['source' => 'unscheduled', 'task_id' => $task->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'dashboard', 'action' => 'red_light_close', 'target_id' => $task->id]);

        // 重算后该红灯不再出现
        $items2 = collect($this->api($this->token)->getJson('/api/dashboard/red-lights')->json('data.items'));
        $this->assertFalse($items2->contains(fn ($r) => $r['source'] === 'unscheduled' && $r['task_id'] === (int) $task->id), '已关闭红灯重算后不应再出现');
    }

    public function test_red_lights_grouped_by_owner(): void
    {
        $this->makeTask(['delivery_date' => now()->addDays(1)->toDateString()]);
        $this->makeTask(['delivery_date' => now()->addDays(1)->toDateString()]);

        $resp = $this->api($this->token)->getJson('/api/dashboard/red-lights');
        $resp->assertOk()->assertJsonPath('data.open_count', 2);

        $groups = $resp->json('data.groups');
        $this->assertNotEmpty($groups);
        $total = array_sum(array_column($groups, 'total'));
        $this->assertSame(2, $total, '红灯按责任人聚合总数一致');
    }
}
