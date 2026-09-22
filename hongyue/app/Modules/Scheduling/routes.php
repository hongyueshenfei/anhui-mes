<?php

use App\Modules\Scheduling\Controllers\ScheduleActionController;
use App\Modules\Scheduling\Controllers\ScheduleDataController;
use App\Modules\Scheduling\Controllers\ScheduleSuggestionController;
use App\Modules\Scheduling\Controllers\ScheduleVersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 排产模块路由（/api/schedule）—— 二期核心
|--------------------------------------------------------------------------
| 架构 §4：排产-数据（task-pool/timeline/orders-affected）+ 排产-操作
| （manual-save/recalculate/insert/insert-confirm/insert-rollback/auto/auto-poll/auto-accept/lock/unlock）
| + 排产版本（versions/rollback）。全部写操作走 audit.log 留痕。
*/
Route::prefix('schedule')->middleware('auth.api')->group(function () {
    // ---- 排产-数据（读） ----
    Route::get('/task-pool', [ScheduleDataController::class, 'taskPool'])->middleware('check.permission:schedule.view');
    Route::get('/timeline', [ScheduleDataController::class, 'timeline'])->middleware('check.permission:schedule.view');
    Route::get('/details', [ScheduleDataController::class, 'details'])->middleware('check.permission:schedule.view');
    Route::get('/orders-affected', [ScheduleDataController::class, 'ordersAffected'])->middleware('check.permission:schedule.view,order.view');
    Route::get('/detail', [ScheduleDataController::class, 'detail'])->middleware('check.permission:schedule.view');
    Route::get('/param-backcalc', [ScheduleDataController::class, 'paramBackcalc'])->middleware('check.permission:schedule.view');

    // ---- 排产-操作（写） ----
    Route::post('/manual-save', [ScheduleActionController::class, 'manualSave'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,schedule_manual_save');
    Route::post('/recalculate', [ScheduleActionController::class, 'recalculate'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,schedule_recalculate');
    Route::post('/operation-adjust', [ScheduleActionController::class, 'operationAdjust'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,schedule_operation_adjust');
    Route::post('/insert', [ScheduleActionController::class, 'insert'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,schedule_insert_preview');
    Route::post('/insert/confirm', [ScheduleActionController::class, 'insertConfirm'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,schedule_insert_confirm');
    Route::post('/insert/rollback', [ScheduleActionController::class, 'insertRollback'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,schedule_insert_rollback');
    Route::post('/auto', [ScheduleActionController::class, 'auto'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,schedule_auto_submit');
    Route::get('/auto/{jobId}', [ScheduleActionController::class, 'autoPoll'])->middleware('check.permission:schedule.view');
    Route::post('/auto/accept', [ScheduleActionController::class, 'autoAccept'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,schedule_auto_accept');
    Route::post('/lock', [ScheduleActionController::class, 'lock'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,schedule_lock');
    Route::post('/unlock', [ScheduleActionController::class, 'unlock'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,unlock_task');

    // ---- 排产版本 ----
    Route::get('/versions', [ScheduleVersionController::class, 'index'])->middleware('check.permission:schedule.view');
    Route::get('/versions/{id}', [ScheduleVersionController::class, 'show'])->middleware('check.permission:schedule.view');
    Route::post('/versions/{id}/rollback', [ScheduleVersionController::class, 'rollback'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,schedule_rollback');

    // ---- APS 顺延建议（一期：建议层——系统生成，计划员采纳/驳回，绝不自动改排程） ----
    Route::get('/suggestions', [ScheduleSuggestionController::class, 'index'])->middleware('check.permission:schedule.view');
    Route::post('/suggestions/{id}/accept', [ScheduleSuggestionController::class, 'accept'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,suggestion_accept');
    Route::post('/suggestions/{id}/dismiss', [ScheduleSuggestionController::class, 'dismiss'])
        ->middleware('check.permission:schedule.manage')->middleware('audit.log:scheduling,suggestion_dismiss');
});
