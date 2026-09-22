<?php

namespace App\Modules\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Services\K3Client;
use App\Modules\Integration\Services\K3ProdOrderPullService;
use App\Modules\Integration\Services\MaterialSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 集成手动操作（四期验收 D4/D6/D9）
 *
 * - POST /api/integration/pull-prod-orders  手动拉单（立即生效）
 * - GET  /api/integration/k3-health          K3 连接测试
 * - POST /api/integration/sync-materials     手动物料同步
 * - GET  /api/integration/materials/unmatched 未匹配物料提示清单
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly K3ProdOrderPullService $pullService,
        private readonly MaterialSyncService $materialSyncService,
        private readonly K3Client $k3Client,
    ) {
    }

    /**
     * 手动拉取金蝶生产订单（增量游标 + 幂等）
     */
    public function pullProdOrders(Request $request): JsonResponse
    {
        $result = $this->pullService->pull($request->user()?->id);

        return $this->ok($result, 'ok');
    }

    /**
     * K3 连接测试
     */
    public function k3Health(): JsonResponse
    {
        return $this->ok($this->k3Client->health());
    }

    /**
     * 手动物料同步（拉取 → 对齐产品编码 → 未匹配清单）
     */
    public function syncMaterials(Request $request): JsonResponse
    {
        $result = $this->materialSyncService->sync($request->user()?->id);

        return $this->ok($result, 'ok');
    }

    /**
     * 未匹配物料提示清单
     */
    public function unmatchedMaterials(): JsonResponse
    {
        return $this->ok($this->materialSyncService->unmatchedList());
    }
}
