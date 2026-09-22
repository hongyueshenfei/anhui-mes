<?php

namespace App\Modules\Plm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Mold;
use App\Models\Product;
use App\Modules\Plm\Requests\MoldRequest;
use App\Modules\Plm\Services\MoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 模具台账控制器（验收 A10：建注塑模（模穴数/寿命/造价）→ 关联产品与适用机台 → 保养计划 → 到期提醒）
 */
class MoldController extends Controller
{
    public function __construct(private readonly MoldService $moldService)
    {
    }

    /**
     * 模具列表（分页 + 过滤 type/status + 保养到期）
     */
    public function index(Request $request): JsonResponse
    {
        $query = Mold::query()
            ->when($request->input('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('product_id'), function ($q, $productId) {
                $q->where(function ($w) use ($productId) {
                    $w->whereHas('products', fn ($p) => $p->whereKey((int) $productId))
                        ->orWhereIn('id', Product::whereKey((int) $productId)->whereNotNull('default_mold_id')->pluck('default_mold_id'));
                });
            })
            ->when($request->input('maintain_due'), function ($q) {
                $q->whereNotNull('next_maintain_at')->where('next_maintain_at', '<=', now());
            })
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('code', 'like', "%{$keyword}%")->orWhere('name', 'like', "%{$keyword}%"));
            })
            ->orderBy('code');

        if ($request->boolean('all')) {
            return $this->ok($query->get());
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return $this->ok($query->paginate($perPage));
    }

    /**
     * 模具详情（含寿命/保养提醒/产品/机台关联）
     */
    public function show(int $id): JsonResponse
    {
        $mold = Mold::findOrFail($id);

        return $this->ok($this->moldService->detail($mold));
    }

    /**
     * 新增模具
     */
    public function store(MoldRequest $request): JsonResponse
    {
        try {
            $mold = $this->moldService->create($request->validated());
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($mold, '模具创建成功', 201);
    }

    /**
     * 更新模具（含产品/机台关联）
     */
    public function update(MoldRequest $request, int $id): JsonResponse
    {
        $mold = Mold::findOrFail($id);

        $data = $request->validated();
        $data['operator_id'] = $request->user()?->id;

        try {
            $mold = $this->moldService->update($mold, $data);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($mold, '模具更新成功');
    }

    /**
     * 模具寿命流水（GET /api/plm/molds/{id}/life）
     */
    public function life(int $id): JsonResponse
    {
        $mold = Mold::findOrFail($id);

        $logs = $mold->lifeLogs()
            ->with('task.line.order:id,order_no')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return $this->ok([
            'mold' => ['id' => $mold->id, 'code' => $mold->code, 'opened_count' => $mold->opened_count],
            'logs' => $logs,
        ]);
    }

    /**
     * 登记保养/维修（POST /api/plm/molds/{id}/maintain）
     * 请求体：{type: maintain|repair, at?, cost?, vendor?, remark?}
     */
    public function maintain(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'in:maintain,repair'],
            'at' => ['nullable', 'date'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'vendor' => ['nullable', 'string', 'max:128'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $mold = Mold::findOrFail($id);

        $data = $request->all();
        $data['operator_id'] = $request->user()?->id;

        $record = $this->moldService->addMaintainRecord($mold, $data);

        return $this->ok($record, '保养/维修记录已登记', 201);
    }

    /**
     * 三匹配基础：模具兼容性（GET /api/plm/molds/{id}/compatibilities）
     * 返回模具关联的产品与适用机台（二期排产强制校验用）
     */
    public function compatibilities(int $id): JsonResponse
    {
        $mold = Mold::with(['products:id,code,name', 'machines:id,code,name'])->findOrFail($id);

        return $this->ok([
            'mold' => ['id' => $mold->id, 'code' => $mold->code, 'type' => $mold->type],
            'products' => $mold->products,
            'machines' => $mold->machines,
        ]);
    }

    /**
     * 删除模具（软删除；有寿命记录/被产品引用时返回 409 拒绝）
     */
    public function destroy(int $id): JsonResponse
    {
        $mold = Mold::findOrFail($id);

        if ($mold->lifeLogs()->count() > 0 || $mold->opened_count > 0) {
            return response()->json([
                'code' => 409,
                'message' => '模具已有寿命流水，不可删除（可置为报废状态）',
                'data' => ['opened_count' => $mold->opened_count],
            ], 409);
        }

        // 引用保护：被产品关联（mold_product）或设为默认模具（products.default_mold_id）时拒绝，避免孤儿 pivot
        // pivot 连表查询需表前缀限定，避免与关联表同名列歧义
        $products = $mold->products()->get(['products.id', 'products.code', 'products.name']);
        if ($products->isNotEmpty() || \App\Models\Product::where('default_mold_id', $mold->id)->exists()) {
            return response()->json([
                'code' => 409,
                'message' => '模具已被产品关联，不可删除。请先在产品档案中解除关联',
                'data' => ['products' => $products],
            ], 409);
        }

        $mold->delete();

        return $this->ok(null, '模具已删除');
    }
}
