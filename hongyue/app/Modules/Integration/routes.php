<?php

use App\Modules\Integration\Controllers\IntegrationController;
use App\Modules\Integration\Controllers\IntegrationLogController;
use App\Modules\Integration\Controllers\MpProgressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integration 模块路由 —— 三期金蝶集成 + 四期拉单/BOM/物料/小程序回写
|--------------------------------------------------------------------------
| 三期：GET /api/system/integration-logs（推送留痕/对账）
| 四期：
|   POST /api/integration/pull-prod-orders   手动拉单（验收 D4）
|   GET  /api/integration/k3-health          K3 连接测试（验收 D9）
|   POST /api/integration/sync-materials     手动物料同步（验收 D6）
|   GET  /api/integration/materials/unmatched 未匹配物料提示清单
|   GET  /api/mp/*                           小程序进度回写契约（appKey+HMAC，验收 D7）
*/
Route::prefix('system')->middleware('auth.api')->group(function () {
    Route::get('/integration-logs', [IntegrationLogController::class, 'index'])->middleware('check.permission:integration.view,audit.view');
    Route::get('/integration-logs/{id}', [IntegrationLogController::class, 'show'])->middleware('check.permission:integration.view,audit.view');
});

// 四期集成手动操作（演示/联调配置页）
Route::prefix('integration')->middleware('auth.api')->group(function () {
    Route::get('/k3-health', [IntegrationController::class, 'k3Health'])->middleware('check.permission:integration.view');
    Route::post('/pull-prod-orders', [IntegrationController::class, 'pullProdOrders'])
        ->middleware('check.permission:integration.manage')->middleware('audit.log:integration,pull_prod_order');
    Route::post('/sync-materials', [IntegrationController::class, 'syncMaterials'])
        ->middleware('check.permission:integration.manage')->middleware('audit.log:integration,sync_materials');
    Route::get('/materials/unmatched', [IntegrationController::class, 'unmatchedMaterials'])->middleware('check.permission:integration.view');
});

// 小程序进度回写契约（独立鉴权：appKey + HMAC-SHA256，无需用户 Token）
Route::prefix('mp')->middleware('mp.auth')->group(function () {
    Route::get('/progress', [MpProgressController::class, 'index']);
    Route::get('/orders/{orderNo}/progress', [MpProgressController::class, 'order']);
    Route::get('/products/{productCode}/progress', [MpProgressController::class, 'product']);
});
