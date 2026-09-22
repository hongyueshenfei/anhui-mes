<?php

use App\Modules\Warehouse\Controllers\StockInController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Warehouse 模块路由（/api/warehouse）—— 三期分批入库
|--------------------------------------------------------------------------
| 架构 §4：可入库量 / 确认入库 / 推送金蝶 / 列表
*/
Route::prefix('warehouse')->middleware('auth.api')->group(function () {
    Route::get('/available-qty', [StockInController::class, 'availableQty'])->middleware('check.permission:warehouse.view,warehouse.manage');
    Route::get('/pending-tasks', [StockInController::class, 'pendingTasks'])->middleware('check.permission:warehouse.view,warehouse.manage');
    Route::get('/ins', [StockInController::class, 'index'])->middleware('check.permission:warehouse.view,warehouse.manage');
    Route::post('/ins', [StockInController::class, 'store'])
        ->middleware('check.permission:warehouse.manage')->middleware('audit.log:warehouse,stock_in_create');
    Route::post('/ins/{id}/push', [StockInController::class, 'push'])
        ->middleware('check.permission:warehouse.manage')->middleware('audit.log:warehouse,stock_in_push');
});