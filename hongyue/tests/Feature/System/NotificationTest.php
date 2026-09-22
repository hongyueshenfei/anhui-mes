<?php

namespace Tests\Feature\System;

use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProdTask;
use App\Models\Product;
use App\Models\User;
use App\Modules\System\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 企微消息通知（四期验收 D8）
 *
 * - 红灯 refresh 后异步推送责任人：通知记录生成（template=red_light，mock_mode 模拟发送）
 * - mock_mode=true 模拟发送落库 sent；真实推送失败仅留痕 failed（不阻断业务）
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->loginToken('admin');
        config(['wecom.mock_mode' => true]);
    }

    public function test_red_light_refresh_creates_notification_for_owner(): void
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        $order = Order::create([
            'order_no' => 'SO-NTF-'.uniqid(),
            'src' => Order::SRC_MANUAL,
            'customer_name' => '通知测试客户',
            'status' => Order::STATUS_PENDING,
            'priority' => 3,
            'delivery_date' => now()->addDays(1),
        ]);
        $line = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1000,
            'delivery_date' => now()->addDays(1),
            'priority' => 3,
            'status' => OrderLine::STATUS_PENDING,
            'done_qty' => 0,
            'mold_id' => $product->default_mold_id,
            'line_no' => 1,
        ]);
        $task = ProdTask::create([
            'task_no' => 'TSK-NTF-'.uniqid(),
            'order_line_id' => $line->id,
            'product_id' => $product->id,
            'route_id' => $product->default_route_id,
            'mold_id' => $product->default_mold_id,
            'qty' => 1000,
            'done_qty' => 0,
            'status' => ProdTask::STATUS_UNSCHEDULED,
            'priority' => 3,
            'delivery_date' => now()->addDays(1)->toDateString(),
            'deviation_pct' => 0,
            'plan_start' => null,
            'plan_end' => null,
            'is_locked' => 0,
            'source' => 'manual',
        ]);

        // 前置：确认红灯存在
        $resp = $this->api($this->token)->getJson('/api/dashboard/red-lights?fresh=1');
        $resp->assertOk();
        $this->assertSame(1, $resp->json('data.open_count'), '前置：应存在未排产红灯');

        // refresh → 异步推送责任人（sync 队列立即执行）
        $this->api($this->token)->postJson('/api/dashboard/red-lights/refresh')->assertOk();

        $records = Notification::where('template', Notification::TEMPLATE_RED_LIGHT)
            ->where('target_type', 'task')
            ->where('target_id', $task->id)
            ->get();

        $this->assertNotEmpty($records, '红灯 refresh 后应生成通知记录');

        // 责任人 = 计划员（P001）
        $planner = User::where('employee_no', 'P001')->firstOrFail();
        $record = $records->first();
        $this->assertSame((int) $planner->id, (int) $record->user_id, '通知应推送红灯责任人（计划员）');
        $this->assertSame('sent', $record->status, 'mock_mode 沙箱模拟发送应为 sent');
        $this->assertSame('wecom', $record->channel);
        $this->assertStringContainsString($task->task_no, (string) $record->content);
    }

    public function test_send_wecom_mock_mode_simulates_sent(): void
    {
        $planner = User::where('employee_no', 'P001')->firstOrFail();

        $record = app(NotificationService::class)->sendWecom(
            (int) $planner->id,
            Notification::TEMPLATE_DUE_SOON,
            '临期提醒',
            '订单 SO-1 交期临近',
            'order',
            1
        );

        $this->assertSame('sent', $record->status);
        $this->assertNotNull($record->sent_at);
    }

    public function test_send_wecom_real_mode_failure_only_logs(): void
    {
        config(['wecom.mock_mode' => false]); // 未配置 corp 凭证
        $planner = User::where('employee_no', 'P001')->firstOrFail();

        $record = app(NotificationService::class)->sendWecom(
            (int) $planner->id,
            Notification::TEMPLATE_RED_LIGHT,
            '红灯提醒',
            '测试失败留痕',
            'task',
            1
        );

        $this->assertSame('failed', $record->status, '真实推送失败应仅留痕 failed，不抛异常');
        $this->assertNotEmpty($record->error_msg);
    }

    public function test_due_soon_order_notification_on_refresh(): void
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        Order::create([
            'order_no' => 'SO-DUE-'.uniqid(),
            'src' => Order::SRC_MANUAL,
            'customer_name' => '临期客户',
            'status' => Order::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->addDays(2),
        ]);
        OrderLine::create([
            'order_id' => Order::where('order_no', 'like', 'SO-DUE-%')->firstOrFail()->id,
            'product_id' => $product->id,
            'qty' => 500,
            'delivery_date' => now()->addDays(2),
            'priority' => 3,
            'status' => OrderLine::STATUS_IN_PROGRESS,
            'done_qty' => 100,
            'mold_id' => $product->default_mold_id,
            'line_no' => 1,
        ]);

        $this->api($this->token)->postJson('/api/dashboard/red-lights/refresh')->assertOk();

        $records = Notification::where('template', Notification::TEMPLATE_DUE_SOON)->get();
        $this->assertNotEmpty($records, '临期订单应生成 due_soon 通知');
        $this->assertStringContainsString('SO-DUE-', (string) $records->first()->content);
    }
}
