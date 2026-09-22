<?php

namespace App\Http\Middleware;

use App\Models\AuditLog as AuditLogModel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 操作审计中间件
 *
 * 用法：->middleware('audit.log:plm,product')
 * 参数：module（模块，必填）、action（动作，可选，默认按 HTTP 方法推断）
 *
 * 只记录写操作（POST/PUT/PATCH/DELETE），读取操作不入审计。
 * 审计要求（一期验收 A11）：BOM 发布、模具修改等关键写操作留痕。
 */
class AuditLog
{
    public function handle(Request $request, Closure $next, string $module, string $action = ''): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        try {
            AuditLogModel::record(
                user: $request->user(),
                module: $module,
                action: $action ?: $this->inferAction($request),
                targetType: $request->route('id') !== null ? $module : null,
                targetId: $request->route('id'),
                detail: [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'params' => $request->except(['password', 'password_hash', 'new_password']),
                    'status' => $response->getStatusCode(),
                ],
                ip: $request->ip(),
            );
        } catch (\Throwable $e) {
            // 审计失败不阻断业务，仅记录日志
            Log::warning('审计写入失败', ['error' => $e->getMessage(), 'path' => $request->path()]);
        }

        return $response;
    }

    /**
     * 按 HTTP 方法推断动作名
     */
    private function inferAction(Request $request): string
    {
        return match ($request->method()) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => 'write',
        };
    }
}