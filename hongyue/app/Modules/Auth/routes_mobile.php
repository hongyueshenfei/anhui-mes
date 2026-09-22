<?php

use App\Modules\Auth\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth 模块 · 移动端路由（/mobile/auth）
|--------------------------------------------------------------------------
| H5 车间端登录通道（三期报工前打通；一期为骨架）
*/
Route::prefix('auth')->group(function () {
    // 微信扫码免密（H5 车间端 BYOD 场景）
    Route::post('/wx-login', [AuthController::class, 'wxLogin']);

    // 首次扫码绑工号
    Route::post('/bind-employee', [AuthController::class, 'bindEmployee']);
});