<?php

namespace Tests\Feature\Mobile;

use App\Models\Machine;
use App\Models\Order;
use App\Models\ProdTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 移动端流程测试（三期 C2/C3 + 免密：模拟凭据走通企微/微信扫码）
 * - 账号密码回退保留
 * - 企微模拟凭据（WECOM_MOCK_MODE）绑定 → 免密直登
 * - 微信 openid 未绑定 → need_bind → 绑工号 → 免密直登
 * - 今日任务（/mobile/tasks 真实数据）、移动报工、我的报工
 */
class MobileFlowTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
    }

    public function test_mobile_health(): void
    {
        $this->getJson('/mobile/health')->assertOk()->assertJsonPath('data.phase', 'P3');
    }

    public function test_wecom_mock_bind_then_login(): void
    {
        // 模拟凭据模式
        config(['wecom.mock_mode' => true]);

        // 微信：首次扫码未绑定 → need_bind
        $first = $this->postJson('/mobile/auth/wx-login', ['openid' => 'mock-openid-1']);
        $first->assertOk()->assertJsonPath('data.need_bind', true);

        // 绑工号（W001）
        $bind = $this->postJson('/mobile/auth/bind-employee', [
            'channel' => 'wx',
            'openid' => 'mock-openid-1',
            'employee_no' => 'W001',
        ]);
        $bind->assertOk();

        // 二次扫码免密直登
        $second = $this->postJson('/mobile/auth/wx-login', ['openid' => 'mock-openid-1']);
        $second->assertOk()->assertJsonPath('data.user.employee_no', 'W001');

        // 企微模拟凭据：未绑定企微的 admin 绑工号（code 即绑定标识）→ 免密直登
        $wecomBind = $this->postJson('/api/auth/bind-employee', [
            'channel' => 'wecom',
            'code' => 'mock-wecom-admin',
            'employee_no' => 'admin',
        ]);
        $wecomBind->assertOk();

        $wecomLogin = $this->postJson('/api/auth/wecom-login', ['code' => 'mock-wecom-admin']);
        $wecomLogin->assertOk()->assertJsonPath('data.user.employee_no', 'admin');

        // 种子账号已有企微 userid 的也可直接免密（W001 wecom_userid=wecom_zhao）
        $this->postJson('/api/auth/wecom-login', ['code' => 'wecom_zhao'])
            ->assertOk()->assertJsonPath('data.user.employee_no', 'W001');
    }

    public function test_password_fallback_still_works(): void
    {
        // 账号密码回退（一期保留）
        $this->postJson('/api/auth/login', ['employee_no' => 'W001', 'password' => '123456'])
            ->assertOk();
    }

    public function test_mobile_today_tasks_lists_scheduled(): void
    {
        $task = $this->scheduledTask();
        $date = now()->addDays(2)->format('Y-m-d');

        $resp = $this->api($this->token)->getJson('/mobile/tasks?date='.$date);
        $resp->assertOk();

        $ids = collect($resp->json('data.data'))->pluck('id');
        $this->assertContains($task->id, $ids->all(), '今日任务应包含已排产任务');
    }

    public function test_mobile_report_flow(): void
    {
        $task = $this->scheduledTask();

        // 移动端报工（扫码来源）
        $this->api($this->token)->postJson('/mobile/reports', [
            'task_id' => $task->id,
            'qty' => 60,
            'source' => 'scan',
        ])->assertStatus(201);

        // 我的报工（当前登录人 admin 为实际作业人）
        $mine = $this->api($this->token)->getJson('/mobile/reports/mine');
        $mine->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(60, $mine->json('data.0.qty'));
    }

    public function test_mobile_task_detail_with_report_summary(): void
    {
        $task = $this->scheduledTask();
        $this->api($this->token)->postJson('/mobile/reports', ['task_id' => $task->id, 'qty' => 30])->assertStatus(201);

        $detail = $this->api($this->token)->getJson("/mobile/tasks/{$task->id}");
        $detail->assertOk()
            ->assertJsonPath('data.report_summary.done_qty', 30)
            ->assertJsonPath('data.report_summary.report_count', 1);
    }

    /** 建单 + 排产（今日起第 3 天） */
    private function scheduledTask(): ProdTask
    {
        $response = $this->api($this->token)->postJson('/api/orders', [
            'customer_name' => '移动端测试',
            'lines' => [['product_code' => 'P-0001', 'qty' => 1000]],
        ])->assertStatus(201);
        $order = Order::findOrFail($response->json('data.id'));
        $task = ProdTask::firstWhere('order_line_id', $order->lines->first()->id);

        $this->api($this->token)->postJson('/api/orders/'.$order->id.'/route-confirm')->assertOk();

        $machine = Machine::where('code', 'I-01')->firstOrFail();
        $start = now()->addDays(2)->startOfDay()->addHours(8);

        $this->api($this->token)->postJson('/api/schedule/manual-save', [
            'items' => [['task_id' => $task->id, 'machine_id' => $machine->id, 'plan_start' => $start->format('Y-m-d H:i:s')]],
        ])->assertOk();

        return $task->fresh();
    }
}