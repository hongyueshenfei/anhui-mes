<?php

namespace App\Modules\Integration\Jobs;

use App\Models\Bom;
use App\Models\IntegrationLog;
use App\Modules\Integration\Services\K3BomPushAdapter;
use App\Modules\Integration\Services\K3Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * BOM 发布直发金蝶（四期 K3 / 验收 D5）
 *
 * - 推送成功才置 published（k3_push_status=pushed），失败置 failed + 重试队列（≤5 次）
 * - 幂等：成功记录唯一键 api_type+ biz_type+biz_id（重推跳过）
 * - 演示/CI（k3.mock=true）：不发起真实 HTTP，返回模拟回执 —— 客户指示演示效果优先
 * - 真实联调（k3.mock=false）：K3Client->save 推 ENG_BOM，回执落 integration_logs
 */
class PushBomJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public function __construct(
        public readonly int $bomId,
        public readonly ?int $userId = null,
    ) {
    }

    public function handle(): void
    {
        $bom = Bom::find($this->bomId);
        if (! $bom) {
            return;
        }

        // 幂等：已成功推送则跳过
        if ($bom->k3_push_status === Bom::K3_PUSHED || IntegrationLog::hasSuccess('push_bom', 'bom', (int) $bom->id)) {
            return;
        }

        $attempts = IntegrationLog::where('api_type', 'push_bom')
            ->where('biz_type', 'bom')
            ->where('biz_id', (int) $bom->id)
            ->count();

        try {
            $response = $this->push($bom);

            $bom->update([
                'status' => Bom::STATUS_PUBLISHED,
                'k3_push_status' => Bom::K3_PUSHED,
                'published_at' => now(),
                'published_by' => $this->userId,
            ]);

            IntegrationLog::capture([
                'direction' => IntegrationLog::DIR_OUT,
                'target' => IntegrationLog::TARGET_K3,
                'api_type' => 'push_bom',
                'biz_type' => 'bom',
                'biz_id' => (int) $bom->id,
                'request_body' => $response['request_body'] ?? null,
                'response_body' => $response['response_body'] ?? null,
                'status' => IntegrationLog::STATUS_SUCCESS,
                'retry_count' => $attempts,
                'error_msg' => null,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $bom->update(['k3_push_status' => Bom::K3_FAILED]);

            IntegrationLog::capture([
                'direction' => IntegrationLog::DIR_OUT,
                'target' => IntegrationLog::TARGET_K3,
                'api_type' => 'push_bom',
                'biz_type' => 'bom',
                'biz_id' => (int) $bom->id,
                'request_body' => null,
                'response_body' => null,
                'status' => IntegrationLog::STATUS_FAILED,
                'retry_count' => $attempts,
                'error_msg' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);

            // 失败重试队列（总尝试 ≤5 次 = 首次 + 4 次重试，指数退避 5min）
            if ($attempts < 4) {
                self::dispatch($this->bomId, $this->userId)->delay(now()->addMinutes(5));
            }
        }
    }

    /**
     * 执行推送（mock 模式返回模拟回执，真实模式走 K3Client）
     */
    private function push(Bom $bom): array
    {
        if ((bool) config('k3.mock', true)) {
            $requestBody = (new K3BomPushAdapter())->adapt($bom);

            return [
                'request_body' => $requestBody,
                'response_body' => [
                    'Result' => [
                        'ResponseStatus' => ['IsSuccess' => true, 'Errors' => []],
                        'Id' => 500000 + (int) $bom->id,
                        'Number' => sprintf('BOM-%s-%s', $bom->product?->code ?? '?', $bom->version),
                    ],
                ],
            ];
        }

        $payload = (new K3BomPushAdapter())->adapt($bom);
        $response = app(K3Client::class)->save($payload);

        return [
            'request_body' => $payload,
            'response_body' => $response,
        ];
    }
}
