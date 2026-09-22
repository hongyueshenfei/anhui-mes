<?php

namespace Tests\Concerns;

use Database\Seeders\DatabaseSeeder;

/**
 * 测试数据种子 Trait：每个用例建库后灌入一期示例数据
 *
 * 注意：Laravel 测试基座的 setUpTraits() 会按 "setUp + Trait短名" 约定自动调用本方法，
 * 因此方法名必须是 setUpWithSeedData（而不是 setUp，否则会被类的 setUp 遮蔽而失效）。
 */
trait WithSeedData
{
    protected function setUpWithSeedData(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * 以指定工号登录并返回 Bearer Token（示例用户默认密码 123456）
     */
    protected function loginToken(string $employeeNo = 'admin', string $password = '123456'): string
    {
        $response = $this->postJson('/api/auth/login', [
            'employee_no' => $employeeNo,
            'password' => $password,
        ]);

        $response->assertOk();

        return (string) $response->json('data.token');
    }

    /**
     * 携带 Token 发起请求（快捷方式）
     */
    protected function api(string $token): static
    {
        return $this->withToken($token);
    }

    /**
     * 工单工艺确认（新流程：订单创建默认 route_confirm，排产前必须确认）
     * 若任务未配工艺则先按默认工艺补齐再确认；返回确认后的订单状态
     */
    protected function routeConfirm(int $orderId, string $token = 'admin'): array
    {
        $token = $token === 'admin' ? $this->loginToken('admin') : $token;
        // 任务缺 route_id 时补产品默认工艺（若产品有已发布默认工艺）
        $order = \App\Models\Order::with('lines.tasks')->findOrFail($orderId);
        foreach ($order->lines as $line) {
            foreach ($line->tasks as $task) {
                if (! $task->route_id && $line->product_id) {
                    $product = \App\Models\Product::find($line->product_id);
                    $task->update(['route_id' => $product?->default_route_id]);
                }
            }
        }
        return $this->withToken($token)->postJson("/api/orders/{$orderId}/route-confirm")->json('data');
    }
}