<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 虹越智造平台 · Laravel 12 应用装配
 *
 * - 统一 API 认证（Sanctum）与权限、审计中间件别名
 * - 统一 JSON 异常响应 {code, message, data}
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // 移动端 H5 路由（前缀 /mobile，独立于 /api）
        then: function (): void {
            require __DIR__.'/../routes/mobile.php';
        },
    )
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        // PC/H5 API 统一使用 Bearer Token（AuthenticateApi），不启用
        // Sanctum statefulApi()，避免浏览器登录被误判为 Cookie 会话请求
        // 并触发不适用的 CSRF 校验（导致 419）。

        $middleware->alias([
            // 统一 JSON 401 的 API 认证中间件
            'auth.api' => \App\Http\Middleware\AuthenticateApi::class,
            // RBAC 权限校验：check.permission:plm.machine.manage
            'check.permission' => \App\Http\Middleware\CheckPermission::class,
            // 操作审计：audit.log:plm,product
            'audit.log' => \App\Http\Middleware\AuditLog::class,
            // 小程序回写契约鉴权：appKey + HMAC-SHA256（mp.auth）
            'mp.auth' => \App\Http\Middleware\MpAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API 请求统一返回 JSON 错误结构
        $exceptions->shouldRenderJsonWhen(function (Request $request): bool {
            return $request->is('api/*') || $request->is('mobile/*') || $request->expectsJson();
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->is('mobile/*') || $request->expectsJson()) {
                return response()->json([
                    'code' => 422,
                    'message' => '参数校验失败',
                    'data' => $e->errors(),
                ], 422);
            }

            return null;
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->is('mobile/*') || $request->expectsJson()) {
                return response()->json([
                    'code' => 404,
                    'message' => '资源不存在',
                    'data' => null,
                ], 404);
            }

            return null;
        });
    })->create();
