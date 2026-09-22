<?php

use App\Modules\Production\Controllers\ReportController;
use App\Modules\Production\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Production 模块 · 移动端路由（/mobile）—— 三期报工
|--------------------------------------------------------------------------
| H5 车间端：今日任务 / 任务详情 / 报工 / 我的报工
*/

// 今日任务（C2）
Route::get('/tasks', [TaskController::class, 'index'])->middleware('auth.api');

// 机台扫码后的当日固定任务上下文（由已接受的排产快照计算）
Route::get('/machines/{code}/report-context', [TaskController::class, 'machineReportContext'])->middleware('auth.api');

// 任务详情（C3 扫码 → 详情）
Route::get('/tasks/{id}', [TaskController::class, 'show'])->middleware('auth.api');

// 报工（扫码/点选/代报/离线补传）
Route::post('/reports', [ReportController::class, 'store'])->middleware('auth.api')->middleware('audit.log:production,mobile_report_save');

// 我的报工（当前阶段按填报操作人查询；不再按个人作业人归属）
Route::get('/reports/mine', function (\Illuminate\Http\Request $request) {
    $reports = \App\Models\WorkReport::with(['task:id,task_no,product_id,qty', 'task.product:id,code,name'])
        ->where('report_by', $request->user()->id)
        ->orderByDesc('report_time')
        ->limit(100)
        ->get();

    return response()->json(['code' => 0, 'message' => 'ok', 'data' => $reports]);
})->middleware('auth.api');
