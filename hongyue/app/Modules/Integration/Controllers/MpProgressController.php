<?php

namespace App\Modules\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Services\MpProgressService;
use Illuminate\Http\JsonResponse;

/**
 * 小程序进度回写契约 API（架构 §5.2 W1 / 验收 D7）
 *
 * 鉴权：appKey + HMAC-SHA256（中间件 mp.auth，测试密钥见 config/mp.php）
 * - GET /api/mp/orders/{orderNo}/progress      订单级进度
 * - GET /api/mp/products/{productCode}/progress 产品级进度
 * - GET /api/mp/progress?order_no=&product_code= 通用查询
 */
class MpProgressController extends Controller
{
    public function __construct(private readonly MpProgressService $service)
    {
    }

    /**
     * 订单级进度
     */
    public function order(string $orderNo): JsonResponse
    {
        try {
            return $this->ok($this->service->orderProgress($orderNo));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 404, 404);
        }
    }

    /**
     * 产品级进度
     */
    public function product(string $productCode): JsonResponse
    {
        try {
            return $this->ok($this->service->productProgress($productCode));
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 404, 404);
        }
    }

    /**
     * 通用查询（order_no / product_code 至少一个）
     */
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $orderNo = trim((string) $request->input('order_no', ''));
        $productCode = trim((string) $request->input('product_code', ''));

        try {
            if ($orderNo !== '') {
                return $this->ok($this->service->orderProgress($orderNo));
            }
            if ($productCode !== '') {
                return $this->ok($this->service->productProgress($productCode));
            }

            return $this->fail('请提供 order_no 或 product_code', 422, 422);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 404, 404);
        }
    }
}
