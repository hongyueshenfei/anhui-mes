<?php

use App\Modules\System\Controllers\AuditController;
use App\Modules\System\Controllers\ConfigController;
use App\Modules\System\Controllers\RoleController;
use App\Modules\System\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| System 模块路由（/api/system）
|--------------------------------------------------------------------------
| 架构 §4：用户/角色/配置/审计。
| 权限：system.user.manage / system.role.manage / system.config.manage / audit.view
*/
Route::prefix('system')->middleware('auth.api')->group(function () {
    // ---- 用户管理 ----
    // 下拉数据源（在职用户，供负责人选择；必须排在 /users/{id} 之前）
    Route::get('/users/options', [UserController::class, 'options'])->middleware('check.permission:order.manage');
    Route::get('/users', [UserController::class, 'index'])->middleware('check.permission:system.user.manage,audit.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('check.permission:system.user.manage')->middleware('audit.log:system,user_create');
    Route::put('/users/{id}', [UserController::class, 'update'])->middleware('check.permission:system.user.manage')->middleware('audit.log:system,user_update');
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->middleware('check.permission:system.user.manage')->middleware('audit.log:system,user_delete');
    Route::post('/users/{id}/roles', [UserController::class, 'assignRoles'])->middleware('check.permission:system.user.manage')->middleware('audit.log:system,user_assign_roles');
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword'])->middleware('check.permission:system.user.manage')->middleware('audit.log:system,user_reset_password');

    // ---- 角色管理 ----
    Route::get('/roles', [RoleController::class, 'index'])->middleware('check.permission:system.role.manage,audit.view');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('check.permission:system.role.manage')->middleware('audit.log:system,role_create');
    Route::put('/roles/{id}', [RoleController::class, 'update'])->middleware('check.permission:system.role.manage')->middleware('audit.log:system,role_update');
    Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->middleware('check.permission:system.role.manage')->middleware('audit.log:system,role_delete');

    // ---- 系统配置 ----
    Route::get('/configs', [ConfigController::class, 'index'])->middleware('check.permission:system.config.manage,audit.view');
    Route::post('/configs', [ConfigController::class, 'store'])->middleware('check.permission:system.config.manage')->middleware('audit.log:system,config_set');

    // ---- 操作审计 ----
    Route::get('/audit-logs', [AuditController::class, 'index'])->middleware('check.permission:audit.view');
});