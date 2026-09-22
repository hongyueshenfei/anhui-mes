<?php

namespace App\Modules\Integration\Services;

use App\Models\IntegrationLog;
use Illuminate\Support\Facades\Http;

/**
 * 小程序适配器（架构 §5.2 W1 平台 → 小程序 进度回写）
 *
 * - pushProgress：向小程序推送订单/产品进度（done_qty/预计完成/延期/可发货量）
 * - 小程序侧端点未就绪（config('mp.push_url') 为 null）时仅留痕 integration_logs
 * - 推送前签名：HMAC-SHA256（与 MpAuth 中间件同构）
 */
class MiniProgramClient
{
    /**
     * 推送进度载荷
     *
     * @return array{accepted: bool, mock: bool, log_id: int|null}
     */
    public function pushProgress(array $payload): array
    {
        $pushUrl = config('mp.push_url');

        if (! $pushUrl) {
            $logId = IntegrationLog::captureAndGetId([
                'direction' => IntegrationLog::DIR_OUT,
                'target' => IntegrationLog::TARGET_MINI_PROGRAM,
                'api_type' => 'push_progress',
                'biz_type' => $payload['level'] ?? 'order',
                'biz_id' => $payload['biz_id'] ?? null,
                'request_body' => $payload,
                'response_body' => null,
                'status' => IntegrationLog::STATUS_SUCCESS,
                'retry_count' => 0,
                'error_msg' => '小程序端点未配置，仅留痕（占位）',
                'finished_at' => now(),
            ]);

            return ['accepted' => true, 'mock' => true, 'log_id' => $logId];
        }

        $timestamp = time();
        $canonical = $timestamp."\nPOST\n".parse_url((string) $pushUrl, PHP_URL_PATH);
        $signature = base64_encode(hash_hmac('sha256', $canonical, (string) config('mp.app_secret'), true));

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-App-Key' => (string) config('mp.app_key'),
                    'X-Timestamp' => (string) $timestamp,
                    'X-Signature' => $signature,
                ])
                ->post((string) $pushUrl, $payload);

            $ok = $response->successful();
            $body = $response->json();

            $logId = IntegrationLog::captureAndGetId([
                'direction' => IntegrationLog::DIR_OUT,
                'target' => IntegrationLog::TARGET_MINI_PROGRAM,
                'api_type' => 'push_progress',
                'biz_type' => $payload['level'] ?? 'order',
                'biz_id' => $payload['biz_id'] ?? null,
                'request_body' => $payload,
                'response_body' => $body,
                'status' => $ok ? IntegrationLog::STATUS_SUCCESS : IntegrationLog::STATUS_FAILED,
                'retry_count' => 0,
                'error_msg' => $ok ? null : mb_substr($response->body(), 0, 500),
                'finished_at' => now(),
            ]);

            return ['accepted' => $ok, 'mock' => false, 'log_id' => $logId];
        } catch (\Throwable $e) {
            $logId = IntegrationLog::captureAndGetId([
                'direction' => IntegrationLog::DIR_OUT,
                'target' => IntegrationLog::TARGET_MINI_PROGRAM,
                'api_type' => 'push_progress',
                'biz_type' => $payload['level'] ?? 'order',
                'biz_id' => $payload['biz_id'] ?? null,
                'request_body' => $payload,
                'response_body' => null,
                'status' => IntegrationLog::STATUS_FAILED,
                'retry_count' => 0,
                'error_msg' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);

            return ['accepted' => false, 'mock' => false, 'log_id' => $logId];
        }
    }
}
