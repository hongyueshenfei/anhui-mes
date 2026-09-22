<?php

use App\Modules\Auth\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth 模块路由（/api/auth）
|--------------------------------------------------------------------------
| 认证通道（架构 §5.3 免密认证）：
| - 账号密码（PC 回退）：POST /api/auth/login
| - 企微 OAuth（优先）：POST /api/auth/wecom-login（code → userid → Token）
| - 微信扫码（H5）：POST /api/auth/wx-login（openid → Token / 引导绑工号）
| - 绑工号：POST /api/auth/bind-employee
| - 登出 / 当前用户：POST /api/auth/logout · GET /api/auth/me
*/
Route::prefix('auth')->group(function () {
    // 账号密码登录（PC 回退）
    Route::post('/login', [AuthController::class, 'login']);

    // 企微扫码登录（一期骨架：无企微配置时返回 501 提示，前端回退账号密码）
    Route::post('/wecom-login', [AuthController::class, 'wecomLogin']);

    // 微信扫码登录（openid → 免密；未绑定返回 need_bind 引导绑工号）
    Route::post('/wx-login', [AuthController::class, 'wxLogin']);

    // 绑定工号（企微/微信首次扫码后绑定员工号）
    Route::post('/bind-employee', [AuthController::class, 'bindEmployee']);

    // ---- 以下需登录 ----
    Route::middleware('auth.api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});