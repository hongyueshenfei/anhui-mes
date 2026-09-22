<?php

namespace App\Modules\Scheduling\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use App\Modules\Scheduling\Services\VersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 排产版本控制器（D9/B6：版本快照 / 回滚）
 *
 * GET  /api/schedule/versions           版本清单
 * GET  /api/schedule/versions/{id}      版本详情（快照可展开）
 * POST /api/schedule/versions/{id}/rollback  回滚到该版本（整版接受/插单回滚共用）
 */
class ScheduleVersionController extends Controller
{
    public function __construct(
        private readonly VersionService $versionService,
    ) {
    }

    /**
     * 版本清单
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        return $this->ok($this->versionService->index($perPage));
    }

    /**
     * 版本详情
     */
    public function show(int $id): JsonResponse
    {
        $version = \App\Models\ScheduleVersion::with('creator:id,name,employee_no')->findOrFail($id);

        return $this->ok($version);
    }

    /**
     * 回滚版本
     */
    public function rollback(Request $request, int $id): JsonResponse
    {
        try {
            $version = $this->versionService->rollback($id, (int) $request->user()?->id);
        } catch (ScheduleException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409;

            return response()->json([
                'code' => $status,
                'message' => $e->getMessage(),
                'data' => ['errors' => $e->errors()],
            ], $status);
        }

        return $this->ok($version, '已回滚到版本 #'.$version->version_no);
    }
}