<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 小程序回写契约鉴权中间件（架构 §5.2 W1 / 验收 D7）
 *
 * appKey + HMAC-SHA256 签名：
 *   Headers: X-App-Key / X-Timestamp / X-Signature
 *   Signature = base64( HMAC-SHA256( app_secret, "{timestamp}\n{METHOD}\n{path}" ) )
 *   时间戳偏差 ≤ config('mp.timestamp_skew_sec')（防重放）
 */
class MpAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $appKey = (string) $request->header('X-App-Key', '');
        $timestamp = (int) $request->header('X-Timestamp', 0);
        $signature = (string) $request->header('X-Signature', '');

        $expectedKey = (string) config('mp.app_key');
        $secret = (string) config('mp.app_secret');
        $skew = (int) config('mp.timestamp_skew_sec', 300);

        if (! hash_equals($expectedKey, $appKey)) {
            return $this->unauthorized('appKey 无效');
        }

        if (abs(time() - $timestamp) > $skew) {
            return $this->unauthorized('时间戳偏差过大（防重放）');
        }

        $canonical = $timestamp."\n".strtoupper($request->method())."\n".$request->path();
        $expected = base64_encode(hash_hmac('sha256', $canonical, $secret, true));

        if (! hash_equals($expected, $signature)) {
            return $this->unauthorized('签名校验失败');
        }

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
