<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Services\DashboardService;
use App\Modules\Dashboard\Services\RedLightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 管理看板（四期）—— 工作台 / 订单总览 / 任务看板 / 产能热力 / 报表
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly RedLightService $redLightService,
    ) {
    }

    /**
     * 工作台（KPI + 红灯速览 + 临期订单 + 今日派工）
     * GET /api/dashboard/workbench
     */
    public function workbench(): JsonResponse
    {
        return $this->ok($this->dashboardService->workbench($this->redLightService));
    }

    /**
     * 订单履约总览（完成/延期/预计延期/未开工/暂停）
     * GET /api/dashboard/order-overview?keyword=&due_flag=&fulfillment_status=
     */
    public function orderOverview(Request $request): JsonResponse
    {
        return $this->ok($this->dashboardService->orderOverview($request->only(['keyword', 'due_flag', 'fulfillment_status'])));
    }

    /**
     * 任务执行看板
     * GET /api/dashboard/task-board
     */
    public function taskBoard(): JsonResponse
    {
        return $this->ok($this->dashboardService->taskBoard());
    }

    /**
     * 产能负荷热力（机台 × 未来 7 天，>85% 预警）
     * GET /api/dashboard/load-heatmap
     */
    public function loadHeatmap(): JsonResponse
    {
        return $this->ok($this->dashboardService->loadHeatmap());
    }

    /**
     * 报表中心
     * GET /api/dashboard/reports
     */
    public function reports(): JsonResponse
    {
        return $this->ok($this->dashboardService->reports());
    }
}
