<?php

namespace App\Modules\Warehouse\Services;

use App\Models\IntegrationLog;
use App\Models\StockIn;
use App\Modules\Integration\Jobs\PushStockInJob;
use App\Modules\Integration\Services\K3Client;
use App\Modules\Integration\Services\K3StockInAdapter;
use Illuminate\Support\Facades\Log;

/**
 * 金蝶生产入库单推送服务（C9/C10：队列 + 重试 5 次 + 幂等 + 日志留痕）
 *
 * - 幂等键：integration_logs (api_type=push_stock_in, biz_type=stock_in, biz_id=id, status=success)
 * - 失败：stock_in.k3_push_status=failed + integration_logs failed/retry 留痕；Job tries=5 自动重试
 * - 手动重推：POST /api/warehouse/ins/{id}/push（先查幂等，成功记录存在则跳过）
 */
class K3StockInPushService
{
    public function __construct(
        private readonly K3Client $k3Client,
        private readonly K3StockInAdapter $adapter,
    ) {
    }

    /**
     * 异步推送（入库确认时调用；QUEUE_CONNECTION=sync 时同步执行）
     */
    public function pushAsync(StockIn $stockIn): void
    {
        PushStockInJob::dispatch($stockIn->id);
    }

    /**
     * 同步推送（Job 与手动重推共用）
     *
     * @return array{pushed: bool, status: string, bill_no: ?string, message: string}
     */
    public function push(StockIn $stockIn): array
    {
        // 幂等：已有成功记录 → 跳过（C10 对账/防重推）
        if (IntegrationLog::hasSuccess('push_stock_in', 'stock_in', (int) $stockIn->id)) {
            return [
                'pushed' => false,
                'status' => StockIn::K3_SUCCESS,
                'bill_no' => $stockIn->k3_bill_no,
                'message' => '该入库单已推送成功（幂等跳过）',
            ];
        }

        $stockIn->update(['k3_push_status' => StockIn::K3_PUSHING]);

        try {
            $payload = $this->adapter->adapt($stockIn);
            $response = $this->k3Client->save($payload);

            // 回执字段（金蝶动态表单：Result.Number 单据编号 / Result.Id）
            $billNo = data_get($response, 'Result.Number')
                ?? data_get($response, 'Result.Id')
                ?? data_get($response, 'bill_no')
                ?? null;

            $stockIn->update([
                'k3_push_status' => StockIn::K3_SUCCESS,
                'ins_status' => StockIn::INS_STATUS_PUSHED,
                'k3_bill_no' => $billNo !== null ? (string) $billNo : $stockIn->k3_bill_no,
                'k3_result' => $response,
            ]);

            IntegrationLog::capture([
                'direction' => IntegrationLog::DIR_OUT,
                'target' => IntegrationLog::TARGET_K3,
                'api_type' => 'push_stock_in',
                'biz_type' => 'stock_in',
                'biz_id' => (int) $stockIn->id,
                'request_body' => $this->maskRequestBody($payload),
                'response_body' => $response,
                'status' => IntegrationLog::STATUS_SUCCESS,
                'retry_count' => $this->retryCount($stockIn->id),
                'error_msg' => null,
                'finished_at' => now(),
            ]);

            return [
                'pushed' => true,
                'status' => StockIn::K3_SUCCESS,
                'bill_no' => $billNo !== null ? (string) $billNo : null,
                'message' => '推送成功',
            ];
        } catch (\Throwable $e) {
            $retryCount = $this->retryCount($stockIn->id);

            $stockIn->update([
                'k3_push_status' => StockIn::K3_FAILED,
                'k3_result' => [
                    'error' => mb_substr($e->getMessage(), 0, 500),
                    'retry_count' => $retryCount,
                    'failed_at' => now()->toDateTimeString(),
                ],
            ]);

            IntegrationLog::capture([
                'direction' => IntegrationLog::DIR_OUT,
                'target' => IntegrationLog::TARGET_K3,
                'api_type' => 'push_stock_in',
                'biz_type' => 'stock_in',
                'biz_id' => (int) $stockIn->id,
                'request_body' => [],
                'response_body' => ['error' => $e->getMessage()],
                'status' => $retryCount > 0 ? IntegrationLog::STATUS_RETRY : IntegrationLog::STATUS_FAILED,
                'retry_count' => $retryCount,
                'error_msg' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);

            // 日志化并向上抛（Job 的 tries 机制驱动重试）
            Log::warning('金蝶入库推送失败', ['stock_in_id' => $stockIn->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * 失败重试次数统计（integration_logs 中该 biz 的失败记录数）
     */
    private function retryCount(int $stockInId): int
    {
        return (int) IntegrationLog::where('api_type', 'push_stock_in')
            ->where('biz_type', 'stock_in')
            ->where('biz_id', $stockInId)
            ->whereIn('status', [IntegrationLog::STATUS_FAILED, IntegrationLog::STATUS_RETRY])
            ->count();
    }

    /**
     * 报文脱敏（密码/凭据等敏感字段不落库）
     */
    private function maskRequestBody(array $payload): array
    {
        $sensitive = ['password', 'acct_id', 'username', 'token', 'session'];
        $masked = $payload;
        foreach ($sensitive as $key) {
            if (array_key_exists($key, $masked)) {
                $masked[$key] = '***';
            }
        }

        return $masked;
    }
}
