<?php

namespace App\Modules\Plm\Services;

use App\Models\AuditLog;
use App\Models\Bom;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Integration\Jobs\PushBomJob;

/**
 * BOM 发布直发金蝶（四期 K3 / 验收 D5）—— 替换一期本地发布占位
 *
 * 流程：置 queued → PushBomJob（mock=模拟回执 / 真实=K3 Save）→
 *       推送成功才置 published（k3_push_status=pushed）；失败置 failed + 重试队列 ≤5 次。
 * 发布动作无论推送结果均写审计（product_id/version/k3_push）。
 */
class BomPublishService
{
    /**
     * 发布 BOM（草稿 → 推送成功 → published）
     */
    public function publish(Bom $bom, ?int $operatorId = null): Bom
    {
        if ($bom->isPublished()) {
            return $bom;
        }

        if ($bom->isDisabled()) {
            throw new \InvalidArgumentException('已禁用的 BOM 不可发布（请先启用）');
        }

        // 同产品唯一发布版本：已发布/已禁用（曾发布占位）均占用名额，拒绝发布
        $conflict = Bom::where('product_id', $bom->product_id)
            ->where('id', '!=', $bom->id)
            ->whereIn('status', [Bom::STATUS_PUBLISHED, Bom::STATUS_DISABLED])
            ->first();
        if ($conflict) {
            throw new \InvalidArgumentException(
                "该产品已有已发布版本 {$conflict->version}，请先退回该版本后再发布"
            );
        }

        if ($bom->lines()->count() === 0) {
            throw new \InvalidArgumentException('BOM 明细为空，无法发布');
        }

        $bom->update(['k3_push_status' => Bom::K3_QUEUED]);

        // 同步队列下立即推送；异步队列下排队（失败自动重试 ≤5 次）
        PushBomJob::dispatch($bom->id, $operatorId);

        $fresh = $bom->fresh();

        AuditLog::record(
            user: $operatorId ? User::find($operatorId) : null,
            module: 'plm',
            action: 'publish_bom',
            targetType: 'bom',
            targetId: $bom->id,
            detail: [
                'product_id' => $bom->product_id,
                'version' => $bom->version,
                'k3_push' => $fresh?->k3_push_status ?? Bom::K3_QUEUED,
            ],
        );

        return $fresh ?? $bom;
    }

    /**
     * 手动重推（集成配置页「失败重推」；幂等跳过已成功）
     */
    public function retry(Bom $bom, ?int $operatorId = null): Bom
    {
        if (IntegrationLog::hasSuccess('push_bom', 'bom', (int) $bom->id)) {
            return $bom;
        }

        $bom->update(['k3_push_status' => Bom::K3_QUEUED]);
        PushBomJob::dispatch($bom->id, $operatorId);

        return $bom->fresh();
    }
}
