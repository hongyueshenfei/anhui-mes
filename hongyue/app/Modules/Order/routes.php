<?php

use App\Modules\Order\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 订单中心模块路由（/api/orders）—— 二期
|--------------------------------------------------------------------------
| 架构 §4：手工建单 / Excel 导入 / 明细查询；金蝶拉单四期接入（K3 拉单后复用 createOrderFromRows）。
*/
Route::prefix('orders')->middleware('auth.api')->group(function () {
    Route::get('/', [OrderController::class, 'index'])->middleware('check.permission:order.view');
    Route::get('/import-template', [OrderController::class, 'importTemplate'])->middleware('check.permission:order.view');
    Route::get('/{id}', [OrderController::class, 'show'])->middleware('check.permission:order.view');
    Route::get('/{id}/lines', [OrderController::class, 'lines'])->middleware('check.permission:order.view');
    Route::post('/', [OrderController::class, 'store'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_create');
    Route::post('/import-excel', [OrderController::class, 'importExcel'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_import');
    Route::post('/tasks/{taskId}/split', [OrderController::class, 'splitTask'])->middleware('check.permission:order.manage')->middleware('audit.log:order,task_split');
    Route::delete('/{id}', [OrderController::class, 'destroy'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_delete');
    Route::patch('/{id}', [OrderController::class, 'update'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_update');
    Route::post('/{id}/start', [OrderController::class, 'start'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_start');
    Route::post('/{id}/complete', [OrderController::class, 'complete'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_complete');
    Route::post('/{id}/revoke', [OrderController::class, 'revoke'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_revoke');
    Route::post('/{id}/pause', [OrderController::class, 'pause'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_pause');
    Route::post('/{id}/abort', [OrderController::class, 'abort'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_abort');
    Route::post('/{id}/close', [OrderController::class, 'close'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_close');
    // 工艺确认流程：配置任务工艺路线 + 确认（route_confirm → pending）
    Route::put('/{id}/routes', [OrderController::class, 'updateRoutes'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_route_edit');
    Route::post('/{id}/route-confirm', [OrderController::class, 'routeConfirm'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_route_confirm');
    // 订单级工艺明细（编辑只对当前工单生效，不回写工艺路线库）
    Route::get('/{id}/route-ops', [OrderController::class, 'taskRouteOps'])->middleware('check.permission:order.view');
    Route::put('/{id}/route-ops', [OrderController::class, 'updateTaskRouteOps'])->middleware('check.permission:order.manage')->middleware('audit.log:order,order_route_ops_edit');
    // 领料清单（按已发布 BOM 计算，只读不落库）
    Route::get('/{id}/pick-list', [OrderController::class, 'pickList'])->middleware('check.permission:order.view');
    Route::get('/{id}/logs', [OrderController::class, 'logs'])->middleware('check.permission:order.view');
    Route::get('/{id}/progress', [OrderController::class, 'progress'])->middleware('check.permission:order.view');
});
