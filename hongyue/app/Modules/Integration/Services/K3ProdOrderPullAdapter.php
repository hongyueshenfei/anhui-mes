<?php

namespace App\Modules\Integration\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 金蝶生产订单拉取适配器（架构 §5.1 K1 / 验收 D4 / P1-1 复合游标）
 *
 * - 增量游标 = 复合双键（FCreatorTime + FBillNo）：
 *     Cache key1 k3.pull_prod_order_cursor         = 游标时间（兼容历史/展示）
 *     Cache key2 k3.pull_prod_order_cursor_bill     = 游标单号（同时间戳内推进）
 *   过滤条件：时间 > 游标时间 或 (时间 = 游标时间 且 FBillNo > 游标单号)
 *   → 同一创建时间多单不会因 TopRowCount/分页边界漏拉（游标推进到 max_time + max_bill_no）
 * - 幂等：单据号（FBillNo）唯一，落库前查重
 * - mock 模式（K3_MOCK=true）：读取 config('k3.mock_orders')，按复合游标过滤
 * - 状态：仅拉取已审核单据（FDocumentStatus='A'）
 */
class K3ProdOrderPullAdapter
{
    public function __construct(private readonly K3Client $client)
    {
    }

    /**
     * 拉取增量生产订单
     *
     * @return array{orders: array, next_cursor: string, pulled_count: int, skipped_count: int, mock: bool}
     */
    public function pull(): array
    {
        [$cursorTime, $cursorBill] = $this->cursor();
        $mock = (bool) config('k3.mock', true);

        $rawOrders = $mock
            ? $this->mockOrdersAfter($cursorTime, $cursorBill)
            : $this->queryK3($cursorTime, $cursorBill);

        $orders = [];
        foreach ($rawOrders as $raw) {
            $lines = [];
            foreach ($raw['FEntity'] ?? [] as $entry) {
                $productCode = data_get($entry, 'FMaterialId.FNumber');
                $qty = (int) data_get($entry, 'FQty', 0);
                $finish = data_get($entry, 'FPlanFinishDate');
                if (! $productCode || $qty <= 0) {
                    continue;
                }
                $lines[] = [
                    'product_code' => $productCode,
                    'qty' => $qty,
                    'delivery_date' => $finish ? date('Y-m-d', strtotime((string) $finish)) : null,
                ];
            }

            if ($lines === []) {
                continue;
            }

            $orders[] = [
                'bill_no' => (string) data_get($raw, 'FBillNo'),
                'customer_name' => (string) data_get($raw, 'FCustName', ''),
                'creator_time' => (string) data_get($raw, 'FCreatorTime', now()->toDateTimeString()),
                'lines' => $lines,
            ];
        }

        $nextCursor = $this->advanceCursor($orders, $cursorTime, $cursorBill);

        return [
            'orders' => $orders,
            'next_cursor' => $nextCursor,
            'pulled_count' => count($orders),
            'skipped_count' => 0,
            'mock' => $mock,
        ];
    }

    /**
     * 当前复合游标 [时间, 单号]（首次均为空串 → 全量）
     *
     * @return array{0: string, 1: string}
     */
    public function cursor(): array
    {
        $time = (string) Cache::get((string) config('k3.pull_cursor_key', 'k3.pull_prod_order_cursor'), '');
        $bill = (string) Cache::get((string) config('k3.pull_cursor_bill_key', 'k3.pull_prod_order_cursor_bill'), '');

        return [$time, $bill];
    }

    /**
     * 推进复合游标到 (max_time, max_bill_no)
     *
     * 时间大于游标时间：时间推进、单号重置为该时间下最大单号；
     * 时间等于游标时间：单号单调递增（不回退）；无新数据：保持不变。
     */
    private function advanceCursor(array $orders, string $currentTime, string $currentBill): string
    {
        $maxTime = '';
        $maxBill = '';

        foreach ($orders as $order) {
            $t = (string) $order['creator_time'];
            $b = (string) $order['bill_no'];
            if ($t > $maxTime || ($t === $maxTime && $b > $maxBill)) {
                $maxTime = $t;
                $maxBill = $b;
            }
        }

        if ($maxTime === '') {
            return $currentTime; // 无新数据，游标不变
        }

        $nextTime = $maxTime > $currentTime ? $maxTime : $currentTime;
        $nextBill = $maxTime === $currentTime
            ? ($maxBill > $currentBill ? $maxBill : $currentBill)
            : $maxBill;

        Cache::forever((string) config('k3.pull_cursor_key', 'k3.pull_prod_order_cursor'), $nextTime);
        Cache::forever((string) config('k3.pull_cursor_bill_key', 'k3.pull_prod_order_cursor_bill'), $nextBill);

        return $nextTime;
    }

    /**
     * mock 数据源：按复合游标过滤（时间 > 游标时间 或 (时间 = 游标时间 且 单号 > 游标单号)）
     */
    private function mockOrdersAfter(string $cursorTime, string $cursorBill): array
    {
        $orders = (array) config('k3.mock_orders', []);

        return array_values(array_filter($orders, function ($row) use ($cursorTime, $cursorBill) {
            if ($cursorTime === '') {
                return true;
            }
            $created = (string) data_get($row, 'FCreatorTime', '');
            $bill = (string) data_get($row, 'FBillNo', '');

            return $created > $cursorTime || ($created === $cursorTime && $bill > $cursorBill);
        }));
    }

    /**
     * 真实 K3 ExecuteBillQuery：按复合游标增量拉取
     */
    private function queryK3(string $cursorTime, string $cursorBill): array
    {
        $filter = "FDocumentStatus = 'A'";
        if ($cursorTime !== '') {
            $filter .= " AND (FCreatorTime > '{$cursorTime}' OR (FCreatorTime = '{$cursorTime}' AND FBillNo > '{$cursorBill}'))";
        }

        try {
            $body = $this->client->executeBillQuery([
                'FormId' => 'PRD_MO',
                'FieldKeys' => 'FBillNo,FCreatorTime,FDocumentStatus,FCustName,FEntity.FMaterialId.FNumber,FEntity.FQty,FEntity.FPlanFinishDate',
                'FilterString' => $filter,
                'OrderString' => 'FCreatorTime ASC, FBillNo ASC',
                'TopRowCount' => 500,
            ]);
        } catch (\Throwable $e) {
            Log::warning('金蝶生产订单拉取查询失败', ['error' => $e->getMessage()]);
            throw $e;
        }

        $result = data_get($body, 'Result', []);

        return is_array($result) ? $result : [];
    }
}
