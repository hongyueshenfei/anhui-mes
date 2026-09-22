<?php

use App\Modules\Quote\Controllers\AiEstimateController;
use App\Modules\Quote\Controllers\QuoteController;
use App\Modules\Quote\Controllers\QuoteParamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 报价中心模块路由（/api/quotes）—— 五期
|--------------------------------------------------------------------------
| 架构 §4 报价：AI 快速估价 / 报价单管理 / 报价参数配置。
| - POST /api/quotes/ai-estimate                 AI 估价（规则引擎 + DeepSeek 辅助降级）
| - GET/POST /api/quotes、GET /{id}              报价单 CRUD
| - PATCH /{id}/price / {id}/status              调价留痕 / 状态流转
| - /params/materials|process-rates|rules        报价参数（生效日期版本化）
*/
Route::prefix('quotes')->middleware('auth.api')->group(function () {
    // AI 估价
    Route::post('/ai-estimate', [AiEstimateController::class, 'estimate'])->middleware('check.permission:quote.view,quote.manage');

    // 报价单
    Route::get('/', [QuoteController::class, 'index'])->middleware('check.permission:quote.view');
    Route::post('/', [QuoteController::class, 'store'])
        ->middleware('check.permission:quote.manage')->middleware('audit.log:quote,quote_create');
    Route::get('/{id}', [QuoteController::class, 'show'])->middleware('check.permission:quote.view');
    Route::patch('/{id}/price', [QuoteController::class, 'adjustPrice'])
        ->middleware('check.permission:quote.manage')->middleware('audit.log:quote,quote_price_change');
    Route::patch('/{id}/status', [QuoteController::class, 'transitionStatus'])
        ->middleware('check.permission:quote.manage')->middleware('audit.log:quote,quote_status');

    // 报价参数
    Route::get('/params/materials', [QuoteParamController::class, 'materials'])->middleware('check.permission:quote.view');
    Route::post('/params/materials', [QuoteParamController::class, 'upsertMaterial'])->middleware('check.permission:quote.manage');
    Route::get('/params/process-rates', [QuoteParamController::class, 'processRates'])->middleware('check.permission:quote.view');
    Route::post('/params/process-rates', [QuoteParamController::class, 'upsertProcessRate'])->middleware('check.permission:quote.manage');
    Route::get('/params/rules', [QuoteParamController::class, 'rules'])->middleware('check.permission:quote.view');
    Route::put('/params/rules', [QuoteParamController::class, 'upsertRule'])->middleware('check.permission:quote.manage');
});
