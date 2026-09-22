<?php

namespace App\Modules\System\Jobs;

use App\Modules\System\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 红灯/临期消息推送 Job（验收 D8）
 *
 * red-lights refresh 后异步推送责任人；失败仅留痕（NotificationService 内部处理），
 * 不抛异常阻断看板接口。同步队列（QUEUE_CONNECTION=sync）下随 refresh 立即执行。
 */
class NotifyRedLightJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly array $openItems,
        public readonly array $dueSoonOrders = [],
    ) {
    }

    public function handle(NotificationService $service): void
    {
        foreach ($this->openItems as $item) {
            $service->notifyRedLight($item);
        }

        foreach ($this->dueSoonOrders as $order) {
            $service->notifyDueSoon($order);
        }
    }
}
