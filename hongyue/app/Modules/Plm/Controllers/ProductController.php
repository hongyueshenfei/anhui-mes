<?php

namespace App\Modules\Plm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProdTask;
use App\Modules\Plm\Requests\ProductRequest;
use App\Modules\Plm\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 产品档案控制器（验收 A7：新建（编码=金蝶物料编码）→ 状态流转有记录）
 */
class ProductController extends Controller
{
    public function __construct(private readonly ProductService $productService)
    {
    }

    /**
     * 产品列表（分页 + 搜索/状态过滤）
     *
     * produced_qty = Σ该产品所有生产任务的 done_qty（报工合格合计，跨任务累计）。
     * 用 selectSub 在 MySQL 单次查询内聚合，避免 N+1；与 prod_tasks.done_qty 字段同步逻辑对账。
     */
    public function index(Request $request): JsonResponse
    {
        $producedQtySub = ProdTask::query()
            ->selectRaw('COALESCE(SUM(done_qty), 0)')
            ->whereColumn('product_id', 'products.id');

        $query = Product::query()
            ->with('defaultMold:id,code,name')
            ->with('defaultRoute:id,name,version,status')
            ->addSelect([
                'produced_qty' => $producedQtySub,
            ])
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('code', 'like', "%{$keyword}%")->orWhere('name', 'like', "%{$keyword}%"));
            })
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('code');

        if ($request->boolean('all')) {
            return $this->ok($query->get());
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return $this->ok($query->paginate($perPage));
    }

    /**
     * 产品详情（含 BOM/工艺/模具关联）
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        return $this->ok($this->productService->detail($product));
    }

    /**
     * 新建产品
     */
    public function store(ProductRequest $request): JsonResponse
    {
        try {
            $product = $this->productService->create($request->validated());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($product, '产品创建成功', 201);
    }

    /**
     * 更新产品基本信息
     */
    public function update(ProductRequest $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->update($request->safe()->except(['default_mold_id', 'default_route_id']));

        try {
            if ($request->has('default_mold_id')) {
                if ($request->input('default_mold_id') === null) {
                    $product->update(['default_mold_id' => null]);
                } else {
                    $this->productService->setDefaultMold($product, (int) $request->input('default_mold_id'));
                }
            }

            if ($request->has('default_route_id')) {
                if ($request->input('default_route_id') === null) {
                    $product->update(['default_route_id' => null]);
                } else {
                    $this->productService->setDefaultRoute($product, (int) $request->input('default_route_id'));
                }
            }
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($product->fresh(), '产品更新成功');
    }

    /**
     * 状态流转（开发→试产→量产/停产；写入审计）
     * 请求体：{status}
     */
    public function transitionStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'in:developing,trial,mass,obsolete'],
        ]);

        $product = Product::findOrFail($id);

        try {
            $product = $this->productService->transitionStatus($product, $request->input('status'), $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok(['id' => $product->id, 'status' => $product->status, 'status_label' => $product->status_label], '状态已更新');
    }

    /**
     * 删除产品（软删除）
     *
     * 引用保护：存在 BOM / 工艺路线 / 模具关联（mold_product）时返回 409 并附清单，
     * 避免孤儿 pivot 与下游引用。二期起还需检查 order_lines / prod_tasks。
     */
    public function destroy(int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $refs = [
            'boms' => $product->boms()->count(),
            'molds' => $product->molds()->count(),
        ];

        if (array_sum($refs) > 0) {
            return response()->json([
                'code' => 409,
                'message' => '产品存在 BOM/模具关联，不可删除（建议状态置为停产）',
                'data' => ['references' => $refs, 'molds' => $product->molds()->get(['molds.id', 'molds.code', 'molds.name'])],
            ], 409);
        }

        $product->delete();

        return $this->ok(null, '产品已删除');
    }
}
