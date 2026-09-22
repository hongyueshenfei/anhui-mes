<?php

use App\Modules\Dashboard\Controllers\DashboardController;
use App\Modules\Dashboard\Controllers\RedLightController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 管理看板模块路由（/api/dashboard）—— 四期
|--------------------------------------------------------------------------
| 架构 §4 看板：工作台 / 订单进度总览 / 任务执行看板 / 产能负荷热力 /
| 红灯管理（四源规则引擎）/ 报表中心。
*/
Route::prefix('dashboard')->middleware('auth.api')->group(function () {
    Route::get('/workbench', [DashboardController::class, 'workbench'])->middleware('check.permission:dashboard.view');
    Route::get('/order-overview', [DashboardController::class, 'orderOverview'])->middleware('check.permission:dashboard.view');
    Route::get('/task-board', [DashboardController::class, 'taskBoard'])->middleware('check.permission:dashboard.view');
    Route::get('/load-heatmap', [DashboardController::class, 'loadHeatmap'])->middleware('check.permission:dashboard.view');
    Route::get('/reports', [DashboardController::class, 'reports'])->middleware('check.permission:dashboard.view');

    // 红灯管理
    Route::get('/red-lights', [RedLightController::class, 'index'])->middleware('check.permission:dashboard.view');
    Route::post('/red-lights/refresh', [RedLightController::class, 'refresh'])->middleware('check.permission:dashboard.view');
    Route::post('/red-lights/{id}/close', [RedLightController::class, 'close'])
        ->middleware('check.permission:dashboard.manage,report.manage')->middleware('audit.log:dashboard,red_light_close');
});
