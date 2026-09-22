<?php

namespace App\Modules\Integration\Jobs;

use App\Models\Order;
use App\Modules\Integration\Services\MiniProgramClient;
use App\Modules\Integration\Services\MpProgressService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 小程序进度回写 Job（架构 §5.2 W1 / 验收 D7）
 *
 * 触发：报工/排产/入库事件 + 定时兜底（routes/console.php 每 30 分钟推送在产订单）。
 * 小程序端点未配置时仅留痕（MiniProgramClient 内部处理）。
 */
class PushProgressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly ?int $orderId = null,
    ) {
    }

    public function handle(MiniProgramClient $client, MpProgressService $service): void
    {
        if ($this->orderId) {
            $order = Order::find($this->orderId);
            if (! $order) {
                return;
            }
            $client->pushProgress($service->orderProgress($order->order_no));

            return;
        }

        // 定时兜底：在产/待排订单全部推送
        Order::whereIn('status', [Order::STATUS_PENDING, Order::STATUS_SCHEDULED, Order::STATUS_IN_PROGRESS])
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (Order $order) use ($client, $service) {
                $client->pushProgress($service->orderProgress($order->order_no));
            });
    }
}
