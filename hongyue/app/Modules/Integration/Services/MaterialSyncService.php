<?php

namespace App\Modules\Integration\Services;

use App\Models\IntegrationLog;
use App\Models\Material;
use App\Models\Product;

/**
 * 物料主数据同步编排（四期 K4 / 验收 D6）
 *
 * - 拉取金蝶物料列表 → materials upsert（编码唯一）
 * - 对齐检查：products.code 在 materials 中不存在 → 未匹配清单（供人工核对）
 * - 留痕：integration_logs（direction=in, target=k3, api_type=sync_material）
 */
class MaterialSyncService
{
    public function __construct(private readonly K3MaterialSyncAdapter $adapter)
    {
    }

    /**
     * 执行一次同步
     *
     * @return array{synced: int, unmatched: int, message: string, items: array}
     */
    public function sync(?int $userId = null): array
    {
        try {
            $materials = $this->adapter->fetch();
        } catch (\Throwable $e) {
            IntegrationLog::capture([
                'direction' => IntegrationLog::DIR_IN,
                'target' => IntegrationLog::TARGET_K3,
                'api_type' => 'sync_material',
                'biz_type' => 'material',
                'biz_id' => null,
                'request_body' => null,
                'response_body' => null,
                'status' => IntegrationLog::STATUS_FAILED,
                'retry_count' => 0,
                'error_msg' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);

            return [
                'synced' => 0,
                'unmatched' => 0,
                'message' => '物料同步失败：'.$e->getMessage(),
                'items' => [],
            ];
        }

        $synced = 0;
        foreach ($materials as $item) {
            Material::upsertByCode($item);
            $synced++;
        }

        $unmatched = $this->unmatchedList();

        IntegrationLog::capture([
            'direction' => IntegrationLog::DIR_IN,
            'target' => IntegrationLog::TARGET_K3,
            'api_type' => 'sync_material',
            'biz_type' => 'material',
            'biz_id' => null,
            'request_body' => ['count' => count($materials)],
            'response_body' => ['synced' => $synced, 'unmatched' => $unmatched['total']],
            'status' => IntegrationLog::STATUS_SUCCESS,
            'retry_count' => 0,
            'error_msg' => null,
            'finished_at' => now(),
        ]);

        return [
            'synced' => $synced,
            'unmatched' => $unmatched['total'],
            'message' => sprintf('物料主数据同步完成：%d 条，未匹配 %d 条', $synced, $unmatched['total']),
            'items' => $unmatched['items'],
        ];
    }

    /**
     * 未匹配清单：产品编码在物料库中不存在（供人工核对/补建物料）
     */
    public function unmatchedList(): array
    {
        $materialCodes = Material::pluck('code')->all();
        $unmatched = Product::query()
            ->orderBy('code')
            ->get()
            ->filter(fn (Product $p) => ! in_array($p->code, $materialCodes, true))
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'matched_product_id' => null,
                'reason' => '金蝶物料库中未找到对应编码',
            ])
            ->values();

        return [
            'total' => $unmatched->count(),
            'items' => $unmatched->all(),
            'message' => '期初物料主数据同步后未匹配项（供人工核对）',
        ];
    }
}
