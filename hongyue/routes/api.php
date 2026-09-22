<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API 路由（统一前缀 /api）—— 自动挂载 Modules 模块路由
|--------------------------------------------------------------------------
| 每个业务模块自治：app/Modules/<Module>/routes.php 内定义自己的路由。
| 新增模块只需在 $modules 数组中追加模块名。
*/

// 健康检查（供 Nginx/Docker healthcheck 与运维使用，无需认证）
Route::get('/health', function () {
    return response()->json([
        'code' => 0,
        'message' => 'ok',
        'data' => [
            'app' => config('app.name'),
            'version' => '1.0.0',
            'phase' => 'P2',
            'time' => now()->toDateTimeString(),
        ],
    ]);
});

// 挂载业务模块路由（一期：Auth/System/Plm；二期：Order/Scheduling；三期：Production/Warehouse/Integration；后续期追加其余模块）
// 挂载业务模块路由（一期：Auth/System/Plm；二期：Order/Scheduling；三期：Production/Warehouse/Integration；四期：Dashboard/Shipping；五期：Quote；后续期追加其余模块）
$modules = ['Auth', 'System', 'Plm', 'Order', 'Scheduling', 'Production', 'Warehouse', 'Integration', 'Dashboard', 'Shipping', 'Quote'];

foreach ($modules as $module) {
    $routeFile = base_path("app/Modules/{$module}/routes.php");
    if (is_file($routeFile)) {
        require $routeFile;
    }
}