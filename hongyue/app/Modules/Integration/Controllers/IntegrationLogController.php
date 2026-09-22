<?php

namespace App\Modules\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 集成日志控制器（C10：推送留痕查询/对账）
 *
 * GET /api/system/integration-logs
 */
class IntegrationLogController extends Controller
{
    /**
     * 集成日志列表（方向/目标/API 类型/状态/业务键过滤）
     */
    public function index(Request $request): JsonResponse
    {
        $query = IntegrationLog::query()
            ->when($request->input('target'), fn ($q, $v) => $q->where('target', $v))
            ->when($request->input('api_type'), fn ($q, $v) => $q->where('api_type', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('biz_type'), fn ($q, $v) => $q->where('biz_type', $v))
            ->when($request->input('biz_id'), fn ($q, $v) => $q->where('biz_id', (int) $v))
            ->orderByDesc('id');

        $perPage = min((int) $request->input('per_page', 15), 100);

        return $this->ok($query->paginate($perPage));
    }

    /**
     * 日志详情
     */
    public function show(int $id): JsonResponse
    {
        return $this->ok(IntegrationLog::findOrFail($id));
    }
}