<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * 控制器基类
 * 统一响应结构：{code, message, data}
 * - code = 0 表示成功；非 0 为业务错误码（与 HTTP 状态码一致，方便前端判别）
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * 成功响应
     */
    protected function ok(mixed $data = null, string $message = 'ok', int $status = 200): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * 失败响应
     */
    protected function fail(string $message = '请求失败', int $code = 400, int $status = 400): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'data' => null,
        ], $status);
    }

    /**
     * 无权限响应（403）
     */
    protected function forbidden(string $message = '无权访问该资源'): JsonResponse
    {
        return $this->fail($message, 403, 403);
    }

    /**
     * 未认证响应（401）
     */
    protected function unauthorized(string $message = '未登录或登录已过期'): JsonResponse
    {
        return $this->fail($message, 401, 401);
    }
}