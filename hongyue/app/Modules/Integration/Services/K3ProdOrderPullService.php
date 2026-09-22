<?php

namespace App\Modules\Integration\Services;

use App\Models\IntegrationLog;
use App\Models\Order;
use App\Modules\Order\Services\OrderService;

/**
 * 金蝶生产订单拉取编排（四期 K1 / 验收 D4）
 *
 * - 幂等：按 k3_bill_no 查重，已存在跳过
 * - 落库：orders(order_no=K3-单号, src=k3, k3_bill_no) + order_lines + prod_tasks（复用 OrderService）
 * - 留痕：integration_logs（direction=in, target=k3, api_type=pull_prod_order, status=success/failed）
 */
class K3ProdOrderPullService
{
    public function __construct(
        private readonly K3ProdOrderPullAdapter $adapter,
        private readonly OrderService $orderService,
    ) {
    }

    /**
     * 执行一次拉取（可被命令 / 手动 API / 定时调度复用）
     *
     * @return array{pulled: int, skipped: int, cursor: string, message: string, mock: bool, errors: array}
     */
    public function pull(?int $userId = null): array
    {
        try {
            $result = $this->adapter->pull();
        } catch (\Throwable $e) {
            IntegrationLog::capture([
                'direction' => IntegrationLog::DIR_IN,
                'target' => IntegrationLog::TARGET_K3,
                'api_type' => 'pull_prod_order',
                'biz_type' => 'prod_order',
                'biz_id' => null,
                'request_body' => ['cursor' => $this->cursorLabel()],
                'response_body' => null,
                'status' => IntegrationLog::STATUS_FAILED,
                'retry_count' => 0,
                'error_msg' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);

            return [
                'pulled' => 0,
                'skipped' => 0,
                'cursor' => $this->cursorLabel(),
                'message' => '拉取失败：'.$e->getMessage(),
                'mock' => (bool) config('k3.mock', true),
                'errors' => [$e->getMessage()],
            ];
        }

        $pulled = 0;
        $skipped = 0;
        $errors = [];

        foreach ($result['orders'] as $order) {
            $billNo = (string) $order['bill_no'];
            if (Order::withTrashed()->where('k3_bill_no', $billNo)->exists()) {
                $skipped++;
                continue;
            }

            try {
                $this->orderService->createOrderFromK3($order['lines'], $billNo, (string) $order['customer_name'], $userId);
                $pulled++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = sprintf('%s：%s', $billNo, mb_substr($e->getMessage(), 0, 200));
            }
        }

        IntegrationLog::capture([
            'direction' => IntegrationLog::DIR_IN,
            'target' => IntegrationLog::TARGET_K3,
            'api_type' => 'pull_prod_order',
            'biz_type' => 'prod_order',
            'biz_id' => null,
            'request_body' => ['cursor' => $result['next_cursor'], 'count' => count($result['orders'])],
            'response_body' => ['pulled' => $pulled, 'skipped' => $skipped],
            'status' => $errors === [] ? IntegrationLog::STATUS_SUCCESS : IntegrationLog::STATUS_FAILED,
            'retry_count' => 0,
            'error_msg' => $errors === [] ? null : mb_substr(implode('；', $errors), 0, 500),
            'finished_at' => now(),
        ]);

        return [
            'pulled' => $pulled,
            'skipped' => $skipped,
            'cursor' => $result['next_cursor'],
            'message' => $errors === []
                ? sprintf('金蝶拉单完成：新增 %d，幂等跳过 %d（游标 %s）', $pulled, $skipped, $result['next_cursor'])
                : sprintf('拉取完成：新增 %d，跳过 %d，失败 %d（详见日志）', $pulled, $skipped, count($errors)),
            'mock' => $result['mock'],
            'errors' => $errors,
        ];
    }

    /**
     * 游标展示标签（复合游标：时间|单号；无单号时仅时间）
     */
    private function cursorLabel(): string
    {
        [$time, $bill] = $this->adapter->cursor();

        return $bill !== '' ? $time.'|'.$bill : $time;
    }
}
