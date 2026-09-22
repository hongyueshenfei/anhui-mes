<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 移动端 H5 路由（车间扫码报工端，统一前缀 /mobile）
|--------------------------------------------------------------------------
| 一期：登录/绑定与今日任务占位；三期：今日任务/任务详情/报工/我的报工。
| 移动端认证使用同一个 Sanctum Token（扫码即 Token）。
*/

Route::prefix('mobile')->group(function () {
    // 移动端健康检查
    Route::get('/health', function () {
        return response()->json(['code' => 0, 'message' => 'ok', 'data' => ['phase' => 'P3']]);
    });

    // 挂载 Auth 模块的移动端登录路由（微信扫码免密/绑工号）
    require base_path('app/Modules/Auth/routes_mobile.php');

    // 三期：Production 模块移动端路由（今日任务/任务详情/报工/我的报工）
    require base_path('app/Modules/Production/routes_mobile.php');
});