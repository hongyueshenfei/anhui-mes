<?php

namespace App\Modules\Integration\Jobs;

use App\Models\StockIn;
use App\Modules\Warehouse\Services\K3StockInPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 金蝶生产入库单推送 Job（C9 异步 + 重试）
 *
 * - tries=5（架构 §5.1：失败最大 5 次 + 手动重推）
 * - backoff 递增退避
 * - 幂等由 K3StockInPushService::push 内 integration_logs 成功记录保证
 */
class PushStockInJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> 递增退避秒数 */
    public array $backoff = [5, 15, 60, 180, 600];

    public function __construct(
        public readonly int $stockInId,
    ) {
    }

    public function handle(K3StockInPushService $pushService): void
    {
        $stockIn = StockIn::find($this->stockInId);

        if (! $stockIn) {
            Log::warning('推送 Job：入库单不存在', ['stock_in_id' => $this->stockInId]);
            $this->delete();

            return;
        }

        $result = $pushService->push($stockIn);

        if ($result['pushed'] === false) {
            // 幂等跳过（已成功）：任务完成
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('金蝶入库推送最终失败（已重试 5 次）', [
            'stock_in_id' => $this->stockInId,
            'error' => $e->getMessage(),
        ]);
    }
}