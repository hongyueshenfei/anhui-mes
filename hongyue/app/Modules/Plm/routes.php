<?php

use App\Modules\Plm\Controllers\BomController;
use App\Modules\Plm\Controllers\MachineController;
use App\Modules\Plm\Controllers\MoldController;
use App\Modules\Plm\Controllers\OpTypeController;
use App\Modules\Plm\Controllers\ProductController;
use App\Modules\Plm\Controllers\RouteController;
use App\Modules\Plm\Controllers\WorkshopController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PLM 模块路由（/api/plm）—— 产品数据底座
|--------------------------------------------------------------------------
| 架构 §4：机台/车间/产品/BOM/工艺路线/模具。
| 权限：manage 写操作；view 读操作（计划员/班组长/工人/业务员只读）。
*/
Route::prefix('plm')->middleware('auth.api')->group(function () {
    // ---- 机台（验收 A5） ----
    Route::get('/machines', [MachineController::class, 'index'])->middleware('check.permission:plm.machine.view');
    Route::get('/machines/{id}', [MachineController::class, 'show'])->middleware('check.permission:plm.machine.view');
    Route::post('/machines', [MachineController::class, 'store'])->middleware('check.permission:plm.machine.manage')->middleware('audit.log:plm,machine_create');
    Route::put('/machines/{id}', [MachineController::class, 'update'])->middleware('check.permission:plm.machine.manage')->middleware('audit.log:plm,machine_update');
    Route::post('/machines/{id}/recalculate-schedules', [MachineController::class, 'recalculateSchedules'])->middleware('check.permission:plm.machine.manage')->middleware('audit.log:plm,machine_schedule_recalculate');
    Route::delete('/machines/{id}', [MachineController::class, 'destroy'])->middleware('check.permission:plm.machine.manage')->middleware('audit.log:plm,machine_delete');

    // ---- 车间（车间实体化：先有车间，车间下面是机台/产线） ----
    Route::get('/workshops', [WorkshopController::class, 'index'])->middleware('check.permission:plm.machine.view');
    Route::get('/workshops/{id}', [WorkshopController::class, 'show'])->middleware('check.permission:plm.machine.view');
    Route::post('/workshops', [WorkshopController::class, 'store'])->middleware('check.permission:plm.machine.manage')->middleware('audit.log:plm,workshop_create');
    Route::put('/workshops/{id}', [WorkshopController::class, 'update'])->middleware('check.permission:plm.machine.manage')->middleware('audit.log:plm,workshop_update');
    Route::delete('/workshops/{id}', [WorkshopController::class, 'destroy'])->middleware('check.permission:plm.machine.manage')->middleware('audit.log:plm,workshop_delete');

    // ---- 产品（验收 A7） ----
    Route::get('/products', [ProductController::class, 'index'])->middleware('check.permission:plm.product.view');
    Route::get('/products/{id}', [ProductController::class, 'show'])->middleware('check.permission:plm.product.view');
    Route::post('/products', [ProductController::class, 'store'])->middleware('check.permission:plm.product.manage')->middleware('audit.log:plm,product_create');
    Route::put('/products/{id}', [ProductController::class, 'update'])->middleware('check.permission:plm.product.manage')->middleware('audit.log:plm,product_update');
    Route::post('/products/{id}/status', [ProductController::class, 'transitionStatus'])->middleware('check.permission:plm.product.manage')->middleware('audit.log:plm,product_status');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->middleware('check.permission:plm.product.manage')->middleware('audit.log:plm,product_delete');

    // ---- BOM（验收 A8） ----
    // 金蝶物料清单样式：全量混合列表（BOM 头 + 明细行交错）+ 按产品编码跳转（半成品跳转入口）
    Route::get('/boms', [BomController::class, 'listAll'])->middleware('check.permission:plm.bom.view')->name('plm.boms.listAll');
    Route::get('/products/by-code/{code}/boms', [BomController::class, 'byProductCode'])->middleware('check.permission:plm.bom.view')->name('plm.boms.byProductCode');
    Route::get('/products/{productId}/boms', [BomController::class, 'index'])->middleware('check.permission:plm.bom.view');
    Route::get('/products/{productId}/boms/published', [BomController::class, 'published'])->middleware('check.permission:plm.bom.view');
    Route::post('/products/{productId}/boms', [BomController::class, 'store'])->middleware('check.permission:plm.bom.manage')->middleware('audit.log:plm,bom_create');
    Route::get('/boms/{id}', [BomController::class, 'show'])->middleware('check.permission:plm.bom.view');
    Route::put('/boms/{id}', [BomController::class, 'update'])->middleware('check.permission:plm.bom.manage')->middleware('audit.log:plm,bom_update');
    // 发布：需要 plm.bom.publish 权限（工程自审，PRD 确认 #19）
    Route::post('/boms/{id}/publish', [BomController::class, 'publish'])->middleware('check.permission:plm.bom.publish')->middleware('audit.log:plm,bom_publish');
    // 生命周期：退回/禁用/启用/删除（均已发布或任意状态软删，走 plm.bom.manage）
    Route::post('/boms/{id}/revoke', [BomController::class, 'revoke'])->middleware('check.permission:plm.bom.manage')->middleware('audit.log:plm,bom_revoke');
    Route::post('/boms/{id}/disable', [BomController::class, 'disable'])->middleware('check.permission:plm.bom.manage')->middleware('audit.log:plm,bom_disable');
    Route::post('/boms/{id}/enable', [BomController::class, 'enable'])->middleware('check.permission:plm.bom.manage')->middleware('audit.log:plm,bom_enable');
    Route::delete('/boms/{id}', [BomController::class, 'destroy'])->middleware('check.permission:plm.bom.manage')->middleware('audit.log:plm,bom_delete');

    // ---- 工序类型字典（可维护化） ----
    Route::get('/op-types', [OpTypeController::class, 'index'])->middleware('check.permission:plm.route.view');
    Route::post('/op-types', [OpTypeController::class, 'store'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,op_type_create');
    Route::put('/op-types/{id}', [OpTypeController::class, 'update'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,op_type_update');
    Route::delete('/op-types/{id}', [OpTypeController::class, 'destroy'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,op_type_delete');

    // ---- 工艺路线（验收 A9） ----
    Route::get('/op-templates', [RouteController::class, 'opTemplates'])->middleware('check.permission:plm.route.view');
    Route::get('/capacity/preview', [RouteController::class, 'previewCapacity'])->middleware('check.permission:plm.route.view');
    // 工艺路线为独立实体（路线库），产品通过 default_route_id 引用
    Route::get('/routes', [RouteController::class, 'index'])->middleware('check.permission:plm.route.view');
    // 全部已发布路线（产品档案「默认工艺」下拉）——必须先于 /routes/{id} 注册
    Route::get('/routes/published-all', [RouteController::class, 'publishedAll'])->middleware('check.permission:plm.route.view');
    Route::post('/routes', [RouteController::class, 'store'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,route_create');
    Route::get('/routes/{id}', [RouteController::class, 'show'])->middleware('check.permission:plm.route.view');
    Route::put('/routes/{id}', [RouteController::class, 'update'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,route_update');
    Route::post('/routes/{id}/publish', [RouteController::class, 'publish'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,route_publish');
    // 生命周期（2026-08-31 编辑工艺优化）：下架（退回草稿）/ 禁用 / 启用 / 删除
    Route::post('/routes/{id}/revoke', [RouteController::class, 'revoke'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,route_revoke');
    Route::post('/routes/{id}/disable', [RouteController::class, 'disable'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,route_disable');
    Route::post('/routes/{id}/enable', [RouteController::class, 'enable'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,route_enable');
    Route::delete('/routes/{id}', [RouteController::class, 'destroy'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,route_delete');
    Route::post('/routes/{id}/copy', [RouteController::class, 'copy'])->middleware('check.permission:plm.route.manage')->middleware('audit.log:plm,route_copy');

    // ---- 模具（验收 A10） ----
    Route::get('/molds', [MoldController::class, 'index'])->middleware('check.permission:plm.mold.view');
    Route::get('/molds/{id}', [MoldController::class, 'show'])->middleware('check.permission:plm.mold.view');
    Route::get('/molds/{id}/life', [MoldController::class, 'life'])->middleware('check.permission:plm.mold.view');
    Route::get('/molds/{id}/compatibilities', [MoldController::class, 'compatibilities'])->middleware('check.permission:plm.mold.view');
    Route::post('/molds', [MoldController::class, 'store'])->middleware('check.permission:plm.mold.manage')->middleware('audit.log:plm,mold_create');
    Route::put('/molds/{id}', [MoldController::class, 'update'])->middleware('check.permission:plm.mold.manage')->middleware('audit.log:plm,mold_update');
    Route::post('/molds/{id}/maintain', [MoldController::class, 'maintain'])->middleware('check.permission:plm.mold.manage')->middleware('audit.log:plm,mold_maintain');
    Route::delete('/molds/{id}', [MoldController::class, 'destroy'])->middleware('check.permission:plm.mold.manage')->middleware('audit.log:plm,mold_delete');
});
