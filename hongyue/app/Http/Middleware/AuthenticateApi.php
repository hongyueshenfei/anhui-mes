<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * API 认证中间件（Sanctum Bearer Token）
 *
 * 直接按 Token 查询 PersonalAccessToken（不经 guard 缓存），保证每个请求
 * 都做真实校验（登出后旧 Token 立即失效；测试环境多请求同进程也正确）。
 * 未认证时返回统一 {code, message, data} JSON 401。
 */
class AuthenticateApi
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $bearerToken = $request->bearerToken();

        $accessToken = $bearerToken ? PersonalAccessToken::findToken($bearerToken) : null;

        if (! $accessToken) {
            return $this->unauthorized('未登录或登录已过期');
        }

        // Token 过期校验（Sanctum expiration 配置）
        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return $this->unauthorized('登录已过期，请重新登录');
        }

        $user = $accessToken->tokenable;

        if (! $user) {
            return $this->unauthorized('未登录或登录已过期');
        }

        // 停用账号禁止访问
        if ((int) $user->status !== 1) {
            return response()->json([
                'code' => 403,
                'message' => '账号已停用，请联系管理员',
                'data' => null,
            ], 403);
        }

        // 关联当前 Token（供控制器 currentAccessToken() 使用）
        $user->withAccessToken($accessToken);
        Auth::setUser($user);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'code' => 401,
            'message' => $message,
            'data' => null,
        ], 401);
    }
}