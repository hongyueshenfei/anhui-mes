<?php

namespace App\Modules\Plm\Services;

use App\Models\AuditLog;
use App\Models\Bom;
use App\Models\BomLine;
use App\Models\IntegrationLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * BOM 服务（版本化；发布动作本期为本地发布，四期替换为直发金蝶）
 */
class BomService
{
    /**
     * 创建新版本 BOM（含明细行）
     * 请求体：{version, remark?, lines: [{item_code, item_name, qty, unit, loss_rate, is_key}]}
     */
    public function createVersion(Product $product, array $data, ?int $operatorId = null): Bom
    {
        return DB::transaction(function () use ($product, $data, $operatorId) {
            $version = $data['version'];

            // 版本唯一性
            if (Bom::where('product_id', $product->id)->where('version', $version)->exists()) {
                throw new \InvalidArgumentException("版本 {$version} 已存在，请换版本号");
            }

            $bom = Bom::create([
                'product_id' => $product->id,
                'version' => $version,
                'status' => Bom::STATUS_DRAFT,
                'k3_push_status' => Bom::K3_NONE,
                'remark' => $data['remark'] ?? null,
            ]);

            $this->syncLines($bom, $data['lines'] ?? []);

            return $bom;
        });
    }

    /**
     * 发布 BOM（草稿→已发布；发布动作留接口占位，四期接 PushBomJob 直发金蝶）
     */
    public function publish(Bom $bom, ?int $operatorId = null): Bom
    {
        if ($bom->isPublished()) {
            return $bom;
        }

        if ($bom->lines()->count() === 0) {
            throw new \InvalidArgumentException('BOM 明细为空，无法发布');
        }

        DB::transaction(function () use ($bom, $operatorId) {
            $bom->update([
                'status' => Bom::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $operatorId,
            ]);

            AuditLog::record(
                user: User::find($operatorId),
                module: 'plm',
                action: 'publish_bom',
                targetType: 'bom',
                targetId: $bom->id,
                detail: ['product_id' => $bom->product_id, 'version' => $bom->version, 'k3_push' => 'deferred_to_phase4'],
            );

            // ============ 四期替换点 ============
            // PushBomJob::dispatch($bom) —— BOM 发布直发金蝶（架构 D10 / 验收 D5）
            // 一期：本地发布，k3_push_status 保持 none
        });

        return $bom->fresh();
    }

    /**
     * 退回：published → draft（清空发布元数据与推送状态，清除历史成功推送记录，记 revoked_*）
     */
    public function revoke(Bom $bom, ?int $operatorId = null): Bom
    {
        if (! $bom->isPublished()) {
            throw new \InvalidArgumentException('仅已发布的 BOM 可退回');
        }
        DB::transaction(function () use ($bom, $operatorId) {
            $bom->update([
                'status' => Bom::STATUS_DRAFT,
                'published_at' => null,
                'published_by' => null,
                'k3_push_status' => Bom::K3_NONE,  // 重置推送状态（退回后可再次发布重新走 K3 流程）
                'revoked_at' => now(),
                'revoked_by' => $operatorId,
            ]);
            // 清除历史成功推送记录（幂等键失效，下次发布需重新推送）
            IntegrationLog::where('api_type', 'push_bom')
                ->where('biz_type', 'bom')
                ->where('biz_id', (int) $bom->id)
                ->where('status', IntegrationLog::STATUS_SUCCESS)
                ->delete();
            AuditLog::record(
                user: User::find($operatorId),
                module: 'plm', action: 'revoke_bom',
                targetType: 'bom', targetId: $bom->id,
                detail: ['product_id' => $bom->product_id, 'version' => $bom->version],
            );
        });

        return $bom->fresh();
    }

    /**
     * 禁用：published → disabled（记 disabled_*，published_at 保留作历史）
     */
    public function disable(Bom $bom, ?int $operatorId = null): Bom
    {
        if (! $bom->isPublished()) {
            throw new \InvalidArgumentException('仅已发布的 BOM 可禁用');
        }
        DB::transaction(function () use ($bom, $operatorId) {
            $bom->update([
                'status' => Bom::STATUS_DISABLED,
                'disabled_at' => now(),
                'disabled_by' => $operatorId,
            ]);
            AuditLog::record(
                user: User::find($operatorId),
                module: 'plm', action: 'disable_bom',
                targetType: 'bom', targetId: $bom->id,
                detail: ['product_id' => $bom->product_id, 'version' => $bom->version],
            );
        });

        return $bom->fresh();
    }

    /**
     * 启用：disabled → published（仅置 status，published_at 不动）
     */
    public function enable(Bom $bom, ?int $operatorId = null): Bom
    {
        if (! $bom->isDisabled()) {
            throw new \InvalidArgumentException('仅已禁用的 BOM 可启用');
        }
        DB::transaction(function () use ($bom, $operatorId) {
            $bom->update([
                'status' => Bom::STATUS_PUBLISHED,
                'disabled_at' => null,
                'disabled_by' => null,
            ]);
            AuditLog::record(
                user: User::find($operatorId),
                module: 'plm', action: 'enable_bom',
                targetType: 'bom', targetId: $bom->id,
                detail: ['product_id' => $bom->product_id, 'version' => $bom->version],
            );
        });

        return $bom->fresh();
    }

    /**
     * 删除：任意状态软删
     */
    public function destroy(Bom $bom, ?int $operatorId = null): void
    {
        $snapshot = ['product_id' => $bom->product_id, 'version' => $bom->version, 'status' => $bom->status];
        $bom->delete();
        AuditLog::record(
            user: User::find($operatorId),
            module: 'plm', action: 'delete_bom',
            targetType: 'bom', targetId: (int) $bom->id,
            detail: $snapshot,
        );
    }

    /**
     * 同步明细行（先清后插，行序按 seq）
     */
    public function syncLines(Bom $bom, array $lines): Bom
    {
        $bom->lines()->delete();

        foreach ($lines as $index => $line) {
            BomLine::create([
                'bom_id' => $bom->id,
                // 行序统一为连续序号 1/2/3（与工艺工序/工序类型口径一致，不再 10 步进）
                'seq' => $line['seq'] ?? ($index + 1),
                'item_code' => $line['item_code'],
                'item_name' => $line['item_name'],
                'qty' => $line['qty'] ?? 1,
                'unit' => $line['unit'] ?? '个',
                'loss_rate' => $line['loss_rate'] ?? 0,
                'is_key' => (int) ($line['is_key'] ?? 0),
            ]);
        }

        return $bom->fresh('lines');
    }

    /**
     * BOM 详情（含明细）
     */
    public function detail(Bom $bom): array
    {
        $bom->load(['lines', 'product:id,code,name', 'publisher:id,employee_no,name']);

        return $bom->toArray();
    }

    /**
     * 产品所有 BOM 版本（含行数与状态）
     */
    public function listByProduct(Product $product): array
    {
        return $product->boms()
            ->withCount('lines')
            ->with('publisher:id,employee_no,name')
            ->get()
            ->toArray();
    }
}
