<?php

namespace App\Modules\Integration\Services;

use App\Models\Order;
use App\Models\ProdTask;
use App\Models\Product;

/**
 * 小程序进度回写契约服务（架构 §5.2 W1 / 验收 D7）
 *
 * - 订单级 / 产品级进度聚合：
 *   done_qty / progress_pct / expected_finish / delayed / shipable_qty（= Σ已入库）
 * - 供小程序侧 GET 查询（appKey+HMAC 鉴权，见 config/mp.php）
 */
class MpProgressService
{
    /**
     * 订单级进度（按 order_no 或 k3_bill_no）
     */
    public function orderProgress(string $orderNo): array
    {
        $order = Order::with(['lines.tasks'])
            ->where('order_no', $orderNo)
            ->orWhere('k3_bill_no', $orderNo)
            ->first();

        if (! $order) {
            throw new \InvalidArgumentException('订单不存在');
        }

        $tasks = $order->lines->flatMap->tasks;

        return $this->buildPayload('order', $order->id, $order->order_no, $tasks, $order->delivery_date?->toDateString(), $order->status);
    }

    /**
     * 产品级进度（按产品编码，跨订单聚合）
     */
    public function productProgress(string $productCode): array
    {
        $product = Product::where('code', $productCode)->first();

        if (! $product) {
            throw new \InvalidArgumentException('产品不存在');
        }

        $tasks = ProdTask::where('product_id', $product->id)->with('line.order')->get();

        $deliveryDate = $tasks
            ->map(fn (ProdTask $t) => $t->delivery_date?->toDateString())
            ->filter()
            ->max();

        return $this->buildPayload('product', (int) $product->id, $productCode, $tasks, $deliveryDate);
    }

    /**
     * 聚合进度载荷（订单级/产品级共用）
     */
    private function buildPayload(string $level, int $bizId, string $bizNo, iterable $tasks, ?string $deliveryDate, ?string $orderStatus = null): array
    {
        $tasks = collect($tasks);

        $qty = (int) $tasks->sum('qty');
        $doneQty = (int) $tasks->sum('done_qty');
        $shipableQty = (int) $tasks->sum('stocked_qty');

        $planEnds = $tasks->filter(fn (ProdTask $t) => $t->plan_end)->pluck('plan_end')->map->toDateTimeString();
        $expectedFinish = $planEnds->max();

        $finished = $tasks->isNotEmpty() && $tasks->every(fn (ProdTask $t) => $t->status === ProdTask::STATUS_DONE);
        $delayed = $deliveryDate !== null
            && ! $finished
            && ($orderStatus === null || $orderStatus !== Order::STATUS_PRODUCED)
            && $deliveryDate < now()->toDateString();

        return [
            'level' => $level,
            'biz_id' => $bizId,
            'biz_no' => $bizNo,
            'order_id' => $level === 'order' ? $bizId : null,
            'order_no' => $level === 'order' ? $bizNo : null,
            'product_id' => $level === 'product' ? $bizId : null,
            'product_code' => $level === 'product' ? $bizNo : null,
            'qty' => $qty,
            'done_qty' => $doneQty,
            'progress_pct' => $qty ? (int) round(($doneQty / $qty) * 100) : 0,
            'expected_finish' => $expectedFinish,
            'delayed' => $delayed,
            'shipable_qty' => $shipableQty,
            'status' => $finished ? 'done' : ($doneQty > 0 ? 'in_progress' : 'pending'),
            'updated_at' => now()->toDateTimeString(),
        ];
    }
}
