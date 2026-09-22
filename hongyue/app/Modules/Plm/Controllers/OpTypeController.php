<?php

namespace App\Modules\Plm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\OpType;
use App\Models\RouteOp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 工序类型字典控制器（可维护化）
 *
 * - op_type 键被 route_ops.op_type 引用：新建后可被工艺路线引用，
 *   编辑时不允许改键（防止历史 route_ops 失联），只允许改名称/属性/排序/启停。
 * - 删除保护：已被工艺路线引用时返回 409，提示改为停用。
 */
class OpTypeController extends Controller
{
    /**
     * 工序类型列表（可搜索 / 按启停过滤）
     */
    public function index(Request $request): JsonResponse
    {
        $query = OpType::query()
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('op_type', 'like', "%{$keyword}%")->orWhere('op_name', 'like', "%{$keyword}%"));
            })
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', 1))
            ->orderBy('sort')
            ->orderBy('id');

        // 引用计数（分页/全量均带，前端展示「被引用 n 次」）
        $attachUsedCount = fn ($items) => $items->map(fn ($t) => $t->toArray() + ['used_count' => $t->usedCount()]);

        if ($request->boolean('all')) {
            return $this->ok($attachUsedCount($query->get()));
        }

        $page = $query->paginate((int) $request->input('per_page', 15));
        $page->setCollection($attachUsedCount($page->getCollection()));

        return $this->ok($page);
    }

    /**
     * 新建工序类型
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'op_type' => ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:op_types,op_type'],
            'op_name' => ['required', 'string', 'max:64'],
            'is_molding' => ['sometimes', 'boolean'],
            'machine_type' => ['nullable', 'string', 'max:16', Rule::in(['injection', 'blow'])],
            'workshop' => ['required', 'string', 'max:16', Rule::in(['injection', 'blow', 'paint', 'effect', 'pack'])],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        // 成型工序必须指定机台类型
        if (! empty($data['is_molding']) && empty($data['machine_type'])) {
            return $this->fail('成型工序必须指定机台类型（注塑/吹塑）', 422, 422);
        }
        // 非成型工序机台类型须为空
        if (empty($data['is_molding']) && ! empty($data['machine_type'])) {
            $data['machine_type'] = null;
        }

        $opType = OpType::create($data);

        return $this->ok($opType, '工序类型创建成功', 201);
    }

    /**
     * 更新工序类型（op_type 键不可改）
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $opType = OpType::findOrFail($id);

        $data = $this->validate($request, [
            'op_name' => ['required', 'string', 'max:64'],
            'is_molding' => ['sometimes', 'boolean'],
            'machine_type' => ['nullable', 'string', 'max:16', Rule::in(['injection', 'blow'])],
            'workshop' => ['required', 'string', 'max:16', Rule::in(['injection', 'blow', 'paint', 'effect', 'pack'])],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['is_molding']) && empty($data['machine_type'])) {
            return $this->fail('成型工序必须指定机台类型（注塑/吹塑）', 422, 422);
        }
        if (empty($data['is_molding']) && ! empty($data['machine_type'])) {
            $data['machine_type'] = null;
        }

        $opType->update($data);

        return $this->ok($opType->fresh(), '工序类型更新成功');
    }

    /**
     * 删除工序类型（引用保护：已被工艺路线引用 → 409 建议停用）
     */
    public function destroy(int $id): JsonResponse
    {
        $opType = OpType::findOrFail($id);

        $used = RouteOp::where('op_type', $opType->op_type)->count();
        if ($used > 0) {
            return response()->json([
                'code' => 409,
                'message' => "该工序类型已被 {$used} 道工艺工序引用，不可删除（建议停用）",
                'data' => ['used_count' => $used],
            ], 409);
        }

        $opType->delete();

        return $this->ok(null, '工序类型已删除');
    }
}
