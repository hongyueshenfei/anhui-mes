<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC 权限校验中间件
 *
 * 用法：->middleware('check.permission:plm.machine.manage')
 * 支持逗号分隔多个权限（满足其一即可）：'check.permission:a,b'
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'code' => 401,
                'message' => '未登录或登录已过期',
                'data' => null,
            ], 401);
        }

        $permissionList = array_filter(array_map('trim', explode(',', $permissions)));

        foreach ($permissionList as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'code' => 403,
            'message' => '无权访问该资源（缺少权限：'.implode(' / ', $permissionList).'）',
            'data' => null,
        ], 403);
    }
}