<?php

namespace App\Modules\Plm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workshop;
use App\Modules\Plm\Requests\WorkshopRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 车间控制器（车间实体化：先有车间，车间下面是机台/产线）
 * 权限沿用 plm.machine.view/manage（车间是机台模块的一部分）。
 */
class WorkshopController extends Controller
{
    /**
     * 车间列表（启用项在前，按 sort）
     */
    public function index(Request $request): JsonResponse
    {
        $query = Workshop::query()
            ->withCount('machines')
            ->orderBy('sort')
            ->orderBy('id');

        if ($request->boolean('active_only')) {
            $query->where('is_active', 1);
        }
        if ($keyword = trim((string) $request->query('keyword', ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%"));
        }

        return $this->ok($query->get());
    }

    /**
     * 车间详情（含机台/产线清单）
     */
    public function show(int $id): JsonResponse
    {
        $workshop = Workshop::with('machines')->findOrFail($id);

        return $this->ok($workshop);
    }

    /**
     * 新建车间
     */
    public function store(WorkshopRequest $request): JsonResponse
    {
        $workshop = Workshop::create($request->validated());

        return $this->ok($workshop, '车间创建成功', 201);
    }

    /**
     * 更新车间
     */
    public function update(WorkshopRequest $request, int $id): JsonResponse
    {
        $workshop = Workshop::findOrFail($id);
        $workshop->update($request->validated());

        return $this->ok($workshop->fresh(), '车间更新成功');
    }

    /**
     * 删除车间（软删；仍有机台时拒绝）
     */
    public function destroy(int $id): JsonResponse
    {
        $workshop = Workshop::findOrFail($id);

        if ($workshop->machines()->count() > 0) {
            return $this->fail('该车间下仍有机台/产线，无法删除（可停用）', 409, 409);
        }

        $workshop->delete();

        return $this->ok(null, '车间已删除');
    }
}
