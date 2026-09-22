<?php

namespace Tests\Feature\Integration;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProdTask;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

/**
 * 小程序进度回写契约测试（四期验收 D7）
 *
 * - appKey + HMAC-SHA256 鉴权（错误签名/时间戳重放 401）
 * - 订单级 / 产品级进度数据正确性（done_qty/进度/预计完成/延期/可发货量=Σ已入库）
 */
class MpProgressContractTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    private function sign(string $method, string $path, ?int $timestamp = null): array
    {
        $ts = $timestamp ?? time();
        $canonical = $ts."\n".strtoupper($method)."\n".$path;
        $sig = base64_encode(hash_hmac('sha256', $canonical, (string) config('mp.app_secret'), true));

        return [
            'X-App-Key' => (string) config('mp.app_key'),
            'X-Timestamp' => (string) $ts,
            'X-Signature' => $sig,
        ];
    }

    private function makeProgressOrder(): array
    {
        $product = Product::where('code', 'P-0001')->firstOrFail();
        $order = Order::create([
            'order_no' => 'SO-MP-'.uniqid(),
            'src' => Order::SRC_MANUAL,
            'customer_name' => '小程序客户',
            'status' => Order::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->subDays(1)->toDateString(),
        ]);
        $line = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'qty' => 1000,
            'delivery_date' => now()->subDays(1)->toDateString(),
            'priority' => 3,
            'status' => OrderLine::STATUS_IN_PROGRESS,
            'done_qty' => 400,
            'mold_id' => $product->default_mold_id,
            'line_no' => 1,
        ]);
        $task = ProdTask::create([
            'task_no' => 'TSK-MP-'.uniqid(),
            'order_line_id' => $line->id,
            'product_id' => $product->id,
            'route_id' => $product->default_route_id,
            'mold_id' => $product->default_mold_id,
            'qty' => 1000,
            'done_qty' => 400,
            'stocked_qty' => 250,
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->subDays(1)->toDateString(),
            'deviation_pct' => 0,
            'plan_start' => now()->subDays(3),
            'plan_end' => now()->addDays(1),
            'is_locked' => 1,
            'source' => 'manual',
        ]);

        return [$order, $task];
    }

    public function test_missing_signature_rejected(): void
    {
        $this->getJson('/api/mp/orders/SO-ANY/progress')->assertStatus(401);
    }

    public function test_bad_signature_rejected(): void
    {
        $headers = $this->sign('GET', 'api/mp/orders/SO-ANY/progress');
        $headers['X-Signature'] = base64_encode('invalid');

        $this->withHeaders($headers)->getJson('/api/mp/orders/SO-ANY/progress')->assertStatus(401);
    }

    public function test_timestamp_replay_rejected(): void
    {
        $headers = $this->sign('GET', 'api/mp/orders/SO-ANY/progress', time() - 3600);

        $this->withHeaders($headers)->getJson('/api/mp/orders/SO-ANY/progress')->assertStatus(401);
    }

    public function test_order_progress_data_correct(): void
    {
        [$order, $task] = $this->makeProgressOrder();

        $path = 'api/mp/orders/'.$order->order_no.'/progress';
        $resp = $this->withHeaders($this->sign('GET', $path))->getJson('/api/mp/orders/'.$order->order_no.'/progress');
        $resp->assertOk();

        $data = $resp->json('data');
        $this->assertSame('order', $data['level']);
        $this->assertSame(1000, $data['qty']);
        $this->assertSame(400, $data['done_qty']);
        $this->assertSame(40, $data['progress_pct']);
        $this->assertSame(250, $data['shipable_qty'], '可发货量 = Σ已入库');
        $this->assertTrue($data['delayed'], '交期已过且未完工应标记延期');
        $this->assertNotNull($data['expected_finish']);
    }

    public function test_product_progress_aggregates_across_orders(): void
    {
        [$order1, $task1] = $this->makeProgressOrder();
        $product = Product::where('code', 'P-0001')->firstOrFail();

        // 第二张单同一产品
        $order2 = Order::create([
            'order_no' => 'SO-MP2-'.uniqid(),
            'src' => Order::SRC_MANUAL,
            'customer_name' => '小程序客户二',
            'status' => Order::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->addDays(5)->toDateString(),
        ]);
        $line2 = OrderLine::create([
            'order_id' => $order2->id,
            'product_id' => $product->id,
            'qty' => 500,
            'delivery_date' => now()->addDays(5)->toDateString(),
            'priority' => 3,
            'status' => OrderLine::STATUS_IN_PROGRESS,
            'done_qty' => 100,
            'mold_id' => $product->default_mold_id,
            'line_no' => 1,
        ]);
        ProdTask::create([
            'task_no' => 'TSK-MP2-'.uniqid(),
            'order_line_id' => $line2->id,
            'product_id' => $product->id,
            'route_id' => $product->default_route_id,
            'mold_id' => $product->default_mold_id,
            'qty' => 500,
            'done_qty' => 100,
            'stocked_qty' => 60,
            'status' => ProdTask::STATUS_IN_PROGRESS,
            'priority' => 3,
            'delivery_date' => now()->addDays(5)->toDateString(),
            'deviation_pct' => 0,
            'plan_start' => now(),
            'plan_end' => now()->addDays(2),
            'is_locked' => 1,
            'source' => 'manual',
        ]);

        $path = 'api/mp/products/P-0001/progress';
        $resp = $this->withHeaders($this->sign('GET', $path))->getJson('/api/mp/products/P-0001/progress');
        $resp->assertOk();

        $data = $resp->json('data');
        $this->assertSame('product', $data['level']);
        $this->assertSame('P-0001', $data['product_code']);
        $this->assertSame(1500, $data['qty'], '跨订单聚合数量');
        $this->assertSame(500, $data['done_qty']);
        $this->assertSame(310, $data['shipable_qty'], '可发货量 = 两单 Σ已入库');
        $this->assertSame(33, $data['progress_pct']);
    }

    public function test_progress_order_not_found_404(): void
    {
        $path = 'api/mp/orders/SO-NOT-EXIST/progress';
        $this->withHeaders($this->sign('GET', $path))->getJson('/api/mp/orders/SO-NOT-EXIST/progress')->assertStatus(404);
    }
}
