<?php

namespace App\Modules\Quote\Services;

use App\Models\Order;
use App\Models\Quote;
use App\Modules\Order\Services\OrderService;

/**
 * 报价转订单（五期 E9 全链路：估价 → 报价单 → 成交 → 订单 → 排产...）
 *
 * 仅成交（won）报价单可转订单；复用 OrderService 生成订单/明细/生产任务。
 */
class QuoteOrderService
{
    public function __construct(private readonly OrderService $orderService)
    {
    }

    /**
     * 成交报价单 → 生产订单
     */
    public function convertToOrder(Quote $quote, ?int $userId = null): Order
    {
        if ($quote->status !== Quote::STATUS_WON) {
            throw new \InvalidArgumentException('仅成交（won）报价单可转订单');
        }

        $rows = [];
        foreach ($quote->lines as $line) {
            if (! $line->product_id) {
                throw new \InvalidArgumentException(sprintf('报价明细「%s」未匹配产品编码，无法转订单', $line->spec_text));
            }
            $rows[] = [
                'product_code' => $line->product->code,
                'qty' => (int) $line->qty,
                'delivery_date' => $quote->suggest_delivery?->toDateString(),
                'priority' => 3,
            ];
        }

        return $this->orderService->createOrder(['lines' => $rows], $userId);
    }
}
