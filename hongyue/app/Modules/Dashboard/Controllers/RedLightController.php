<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Services\DashboardService;
use App\Modules\Dashboard\Services\RedLightService;
use App\Modules\System\Jobs\NotifyRedLightJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 红灯管理（四期验收 D1 / D8）
 *
 * - GET  /api/dashboard/red-lights（按责任人聚合，status/source 过滤）
 * - POST /api/dashboard/red-lights/refresh（强制重算 + 异步推送责任人企微消息）
 * - POST /api/dashboard/red-lights/{id}/close（关闭留审计）
 */
class RedLightController extends Controller
{
    public function __construct(
        private readonly RedLightService $redLightService,
        private readonly DashboardService $dashboardService,
    ) {
    }

    /**
     * 红灯列表（按责任人聚合）
     */
    public function index(Request $request): JsonResponse
    {
        $items = $this->redLightService->list((bool) $request->boolean('fresh'));

        return $this->ok($this->redLightService->grouped(
            $items,
            $request->input('source'),
            $request->input('status')
        ));
    }

    /**
     * 手动重算红灯（清缓存强制重算；异步推送责任人企微消息，失败仅留痕）
     */
    public function refresh(): JsonResponse
    {
        $items = $this->redLightService->list(true);
        $open = array_values(array_filter($items, fn ($r) => $r['status'] === 'open'));

        // 临期订单（3 天内交付未完成）一并推送
        $dueSoon = $this->dashboardService->dueSoonOrders();

        if ($open !== [] || $dueSoon !== []) {
            NotifyRedLightJob::dispatch($open, $dueSoon);
        }

        return $this->ok(['refreshed' => true, 'open_count' => count($open)]);
    }

    /**
     * 关闭红灯（写 red_light_closes + audit_logs）
     */
    public function close(int $id, Request $request): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:512']]);

        try {
            $closed = $this->redLightService->close(
                $id,
                $request->user()?->id,
                trim((string) $request->input('reason')),
                $request->input('closed_by')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 404, 404);
        }

        return $this->ok($closed, '红灯已关闭，审计已留痕');
    }
}
