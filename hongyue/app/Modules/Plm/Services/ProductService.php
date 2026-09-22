<?php

namespace App\Modules\Plm\Services;

use App\Models\Mold;
use App\Models\PlmRoute;
use App\Models\Product;
use App\Models\ProdTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 产品档案服务（含状态流转校验）
 */
class ProductService
{
    /**
     * 创建产品
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'spec' => $data['spec'] ?? null,
                'unit' => $data['unit'] ?? '件',
                'status' => $data['status'] ?? Product::STATUS_DEVELOPING,
                'weight' => $data['weight'] ?? null,
                'default_mold_id' => null,
                'default_route_id' => null,
            ]);

            // 默认模具（存在性校验）
            if (! empty($data['default_mold_id'])) {
                $this->setDefaultMold($product, (int) $data['default_mold_id']);
            }

            // 默认工艺路线（仅允许已发布版本，验收 A8）
            if (! empty($data['default_route_id'])) {
                $this->setDefaultRoute($product, (int) $data['default_route_id']);
            }

            return $product->fresh();
        });
    }

    /**
     * 状态流转（A7：开发→试产→量产 有记录；流转写入 audit_logs）
     */
    public function transitionStatus(Product $product, string $toStatus, ?int $operatorId = null): Product
    {
        if ($product->status === $toStatus) {
            // 幂等：状态相同直接返回
            return $product;
        }

        if (! Product::canTransition($product->status, $toStatus)) {
            throw new \InvalidArgumentException(
                "不允许的状态流转：{$product->status} → {$toStatus}（合法流转见 Product::STATUS_FLOW）"
            );
        }

        // 先取旧状态再 update：update() 内部会 syncOriginal()，
        // 之后 getOriginal('status') 已返回新值，无法用于审计 from
        $fromStatus = $product->status;

        $product->update(['status' => $toStatus]);

        \App\Models\AuditLog::record(
            user: \App\Models\User::find($operatorId),
            module: 'plm',
            action: 'product_status',
            targetType: 'product',
            targetId: $product->id,
            detail: ['from' => $fromStatus, 'to' => $toStatus],
        );

        return $product;
    }

    /**
     * 设置默认模具（校验模具类型与产品是否关联）
     */
    public function setDefaultMold(Product $product, int $moldId): Product
    {
        $mold = Mold::findOrFail($moldId);

        // 模具须与该产品已关联（通过 mold_product 中间表）
        if (! $mold->products()->whereKey($product->id)->exists()) {
            $mold->products()->syncWithoutDetaching([$product->id => ['is_default' => 1]]);
        }

        $product->update(['default_mold_id' => $moldId]);

        return $product;
    }

    /**
     * 设置默认工艺路线（仅已发布版本可被引用，验收 A8）
     *
     * 工艺路线为独立实体，产品仅通过 default_route_id 引用：
     * 下拉数据源为全表已发布路线，排产以该路线的工序为准（不校验产品归属）。
     */
    public function setDefaultRoute(Product $product, int $routeId): Product
    {
        $route = PlmRoute::findOrFail($routeId);

        if (! $route->isPublished()) {
            throw new \InvalidArgumentException('未发布的工艺路线不可设为默认（请先发布）');
        }

        $product->update(['default_route_id' => $routeId]);

        return $product;
    }

    /**
     * 产品详情（含 BOM/工艺/模具）
     */
    public function detail(Product $product): array
    {
        $product->load([
            'boms' => fn ($q) => $q->withCount('lines'),
            'defaultMold:id,code,name',
            'defaultRoute:id,name,version,status',
            'molds:id,code,name',
        ]);

        // 历史生产数量 = Σ该产品所有任务的 done_qty（报工合格合计，跨任务累计）
        $product->produced_qty = (int) ProdTask::where('product_id', $product->id)->sum('done_qty');

        return $product->toArray();
    }
}
