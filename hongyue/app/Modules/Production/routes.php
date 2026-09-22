<?php

use App\Modules\Production\Controllers\ReportController;
use App\Modules\Production\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Production 模块路由（/api）—— 三期报工
|--------------------------------------------------------------------------
| 架构 §4：任务（/api/tasks）、报工（/api/reports）
| 移动端 /mobile/tasks、/mobile/reports 见 routes_mobile.php
*/
Route::prefix('tasks')->middleware('auth.api')->group(function () {
    Route::get('/', [TaskController::class, 'index'])->middleware('check.permission:report.view,order.view,schedule.view');
    Route::get('/{id}', [TaskController::class, 'show'])->middleware('check.permission:report.view,order.view,schedule.view');
    Route::get('/{id}/report-history', [TaskController::class, 'reportHistory'])->middleware('check.permission:report.view');
});

Route::prefix('reports')->middleware('auth.api')->group(function () {
    Route::get('/daily-context', [TaskController::class, 'dailyReportContext'])->middleware('check.permission:report.view');
    Route::get('/summary', [ReportController::class, 'summary'])->middleware('check.permission:report.view');
    Route::get('/', [ReportController::class, 'index'])->middleware('check.permission:report.view');
    Route::post('/', [ReportController::class, 'store'])
        ->middleware('check.permission:report.manage')->middleware('audit.log:production,report_save');
    Route::post('/verify', [ReportController::class, 'verify'])->middleware('check.permission:report.manage');
});
