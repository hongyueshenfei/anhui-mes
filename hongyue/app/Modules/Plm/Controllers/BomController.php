<?php

namespace App\Modules\Plm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Models\Material;
use App\Models\Product;
use App\Modules\Plm\Requests\BomRequest;
use App\Modules\Plm\Services\BomPublishService;
use App\Modules\Plm\Services\BomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * BOM 控制器（验收 A8：建 2 个版本，v1 草稿 → v2 发布；未发布版本不可被引用）
 * 四期：发布走 BomPublishService 直发金蝶（推送成功才置 published）。
 */
class BomController extends Controller
{
    public function __construct(
        private readonly BomService $bomService,
        private readonly BomPublishService $bomPublishService,
    ) {
    }

    /**
     * 产品 BOM 版本列表
     */
    public function index(int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);

        return $this->ok($this->bomService->listByProduct($product));
    }

    /**
     * 全量 BOM 混合列表（金蝶物料清单样式：BOM 头行 + 明细行交错）
     *
     * 入参（可选）：product_code（产品编码）、status（draft/published）、search（模糊搜 BOM 简称/产品名）。
     * 不分页：一期 BOM 数量可控，仅用于工单场景工人查看。
     */
    public function listAll(Request $request): JsonResponse
    {
        $query = Bom::query()
            ->with(['product:id,code,name', 'lines'])
            ->withCount('lines')
            ->orderBy('product_id')
            ->orderBy('id')
            ->orderBy('version');

        if ($productCode = trim((string) $request->query('product_code', ''))) {
            $query->whereHas('product', fn ($q) => $q->where('code', $productCode));
        }

        if ($status = trim((string) $request->query('status', ''))) {
            $query->where('status', $status);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                // bom_short_name 为金蝶同步预留字段；当前表暂无该列，按产品名称搜索兜底
                if (Schema::hasColumn('boms', 'bom_short_name')) {
                    $q->where('bom_short_name', 'like', "%{$search}%");
                }
                $q->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        $boms = $query->get();

        // 半成品判定 + 规格回填：一次性收集全部明细行编码，避免 N+1
        $codes = $boms->flatMap(fn (Bom $bom) => $bom->lines->pluck('item_code'))
            ->unique()
            ->values()
            ->all();

        $semiProductIds = [];
        $materialSpecs = [];
        if ($codes !== []) {
            // item_code 命中产品编码 → 该明细行为半成品（可跳转到该产品 BOM）
            $semiProductIds = Product::whereIn('code', $codes)->pluck('id', 'code')->all();
            // 规格从金蝶同步物料主数据回填（bom_lines 无 spec 列）
            $materialSpecs = Material::whereIn('code', $codes)->pluck('spec', 'code')->all();
        }

        $rows = [];
        foreach ($boms as $bom) {
            $rows[] = [
                'row_type' => 'bom',
                'bom_id' => $bom->id,
                'product_id' => $bom->product_id,
                'product_code' => $bom->product?->code,
                'product_name' => $bom->product?->name,
                'version' => $bom->version,
                'status' => $bom->status,
                'bom_short_name' => $bom->bom_short_name ?? null,
                'lines_count' => $bom->lines_count,
                'published_at' => $bom->published_at?->toDateTimeString(),
                'remark' => $bom->remark,
                'k3_push_status' => $bom->k3_push_status,
            ];

            foreach ($bom->lines as $line) {
                $semiProductId = $semiProductIds[$line->item_code] ?? null;
                $rows[] = [
                    'row_type' => 'line',
                    'bom_id' => $bom->id,
                    'line_id' => $line->id,
                    'seq' => $line->seq,
                    'item_code' => $line->item_code,
                    'item_name' => $line->item_name,
                    'spec' => $materialSpecs[$line->item_code] ?? '',
                    'qty' => number_format((float) $line->qty, 4, '.', ''),
                    'unit' => $line->unit,
                    'loss_rate' => number_format((float) $line->loss_rate, 2, '.', ''),
                    'is_key' => (int) $line->is_key,
                    'is_semi_finished' => $semiProductId !== null,
                    'semi_finished_product_id' => $semiProductId,
                ];
            }
        }

        return $this->ok($rows);
    }

    /**
     * 按产品编码查该产品全部 BOM 版本（金蝶物料编码跳转入口）
     */
    public function byProductCode(string $code): JsonResponse
    {
        $product = Product::where('code', $code)->first();

        if (! $product) {
            return $this->fail('产品不存在', 404, 404);
        }

        return $this->ok($this->bomService->listByProduct($product));
    }

    /**
     * 创建 BOM 新版本
     * 请求体：{version, remark?, lines: [...]}
     */
    public function store(BomRequest $request, int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);

        try {
            $bom = $this->bomService->createVersion($product, $request->validated(), $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($this->bomService->detail($bom), 'BOM 版本创建成功', 201);
    }

    /**
     * BOM 详情（含明细）
     */
    public function show(int $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);

        return $this->ok($this->bomService->detail($bom));
    }

    /**
     * 更新 BOM 明细（仅草稿版本可编辑；已发布版本锁定）
     * 请求体：{lines: [...]}
     */
    public function update(BomRequest $request, int $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);

        if ($bom->isPublished()) {
            return $this->fail('已发布的 BOM 不可修改（请新建版本）', 422, 422);
        }

        if ($bom->isDisabled()) {
            return $this->fail('已禁用的 BOM 不可修改（请新建版本）', 422, 422);
        }

        try {
            $bom = $this->bomService->syncLines($bom, $request->validated()['lines'] ?? []);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($this->bomService->detail($bom), 'BOM 明细更新成功');
    }

    /**
     * 发布 BOM（草稿→推送成功→已发布；四期直发金蝶）
     */
    public function publish(int $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);

        try {
            $bom = $this->bomPublishService->publish($bom, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok([
            'id' => $bom->id,
            'status' => $bom->status,
            'k3_push_status' => $bom->k3_push_status,
            'published_at' => $bom->published_at?->toDateTimeString(),
        ], 'BOM 发布请求已受理（推送成功即生效）');
    }

    /**
     * 退回 BOM：已发布 → 草稿（退回后可编辑）
     */
    public function revoke(int $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);
        try {
            $bom = $this->bomService->revoke($bom, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok(['id' => $bom->id, 'status' => $bom->status, 'revoked_at' => $bom->revoked_at?->toDateTimeString()], 'BOM 已退回为草稿');
    }

    /**
     * 禁用 BOM：已发布 → 已禁用（禁用后不可被排产/订单引用）
     */
    public function disable(int $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);
        try {
            $bom = $this->bomService->disable($bom, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok(['id' => $bom->id, 'status' => $bom->status, 'disabled_at' => $bom->disabled_at?->toDateTimeString()], 'BOM 已禁用');
    }

    /**
     * 启用 BOM：已禁用 → 已发布
     */
    public function enable(int $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);
        try {
            $bom = $this->bomService->enable($bom, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok(['id' => $bom->id, 'status' => $bom->status], 'BOM 已启用');
    }

    /**
     * 删除 BOM：任意状态软删除
     */
    public function destroy(int $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);
        try {
            $this->bomService->destroy($bom, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok(null, 'BOM 已删除');
    }

    /**
     * 获取产品已发布的最新 BOM（排产/引用场景；未发布版本不可被引用）
     */
    public function published(int $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);
        $bom = $product->getPublishedBom();

        if (! $bom) {
            return $this->fail('该产品暂无已发布的 BOM', 404, 404);
        }

        return $this->ok($this->bomService->detail($bom));
    }
}