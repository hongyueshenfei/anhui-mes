<?php

namespace App\Modules\Plm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PlmRoute;
use App\Models\Product;
use App\Modules\Plm\Requests\RouteRequest;
use App\Modules\Plm\Services\RouteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 工艺路线控制器（验收 A9：4 工序配产能参数；产能预览换算；类似产品复制）
 */
class RouteController extends Controller
{
    public function __construct(private readonly RouteService $routeService)
    {
    }

    /**
     * 工艺路线列表（独立路线库，按名称/版本/状态过滤）
     */
    public function index(Request $request): JsonResponse
    {
        $query = PlmRoute::query()->withCount('ops');

        if ($keyword = trim((string) $request->query('keyword', ''))) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('version', 'like', "%{$keyword}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->ok(
            $query->orderBy('name')->orderBy('version')->get()
        );
    }

    /**
     * 全部已发布工艺路线（产品档案「默认工艺」下拉数据源）
     */
    public function publishedAll(): JsonResponse
    {
        $routes = PlmRoute::query()
            ->where('status', PlmRoute::STATUS_PUBLISHED)
            ->withCount('ops')
            ->orderBy('name')
            ->orderBy('version')
            ->get(['id', 'name', 'version', 'status']);

        return $this->ok($routes);
    }

    /**
     * 工艺路线详情（含工序）
     */
    public function show(int $id): JsonResponse
    {
        $route = PlmRoute::with('ops')->findOrFail($id);

        return $this->ok($route);
    }

    /**
     * 创建工艺路线
     * 请求体：{name, version, remark?, ops: [{seq, op_type, op_name, capacity_per_hour, ...}]}
     */
    public function store(RouteRequest $request): JsonResponse
    {
        try {
            $route = $this->routeService->createVersion($request->validated(), $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($route->load('ops'), '工艺路线创建成功', 201);
    }

    /**
     * 更新工艺路线（头信息 + 工序）
     */
    public function update(RouteRequest $request, int $id): JsonResponse
    {
        $route = PlmRoute::findOrFail($id);

        try {
            $route = $this->routeService->update($route, $request->validated(), $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($route, '工艺路线更新成功');
    }

    /**
     * 发布工艺路线
     */
    public function publish(int $id): JsonResponse
    {
        $route = PlmRoute::findOrFail($id);

        try {
            $route = $this->routeService->publish($route, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok(['id' => $route->id, 'status' => $route->status], '工艺路线发布成功');
    }

    /**
     * 下架工艺路线（已发布 → 草稿，退回后可编辑并重新发布）
     */
    public function revoke(int $id): JsonResponse
    {
        $route = PlmRoute::findOrFail($id);

        try {
            $route = $this->routeService->revoke($route, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok(['id' => $route->id, 'status' => $route->status], '工艺路线已下架（退回草稿，可编辑后重新发布）');
    }

    /**
     * 禁用工艺路线（任意状态 → 已禁用）
     */
    public function disable(int $id): JsonResponse
    {
        $route = PlmRoute::findOrFail($id);

        try {
            $route = $this->routeService->disable($route, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok(['id' => $route->id, 'status' => $route->status], '工艺路线已禁用');
    }

    /**
     * 启用工艺路线（已禁用 → 已发布）
     */
    public function enable(int $id): JsonResponse
    {
        $route = PlmRoute::findOrFail($id);

        try {
            $route = $this->routeService->enable($route, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok(['id' => $route->id, 'status' => $route->status], '工艺路线已启用');
    }

    /**
     * 删除工艺路线（任意状态软删；存在引用时 409 保护）
     */
    public function destroy(int $id): JsonResponse
    {
        $route = PlmRoute::findOrFail($id);

        try {
            $this->routeService->destroy($route, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 409, 409);
        }

        return $this->ok(null, '工艺路线已删除');
    }

    /**
     * 复制工艺路线（验收 A9：复制成功）
     * 请求体：{name, version, remark?}
     */
    public function copy(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'version' => ['required', 'string', 'max:16'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        $sourceRoute = PlmRoute::with('ops')->findOrFail($id);

        try {
            $newRoute = $this->routeService->copyFromRoute(
                $sourceRoute,
                $request->input('name'),
                $request->input('version'),
                $request->input('remark'),
                $request->user()?->id,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($newRoute->load('ops'), '工艺复制成功（新版本为草稿，发布后可用）', 201);
    }

    /**
     * 标准工序模板（工序模板选择器）
     */
    public function opTemplates(): JsonResponse
    {
        return $this->ok($this->routeService->opTemplates());
    }

    /**
     * 产能预览换算（验收 A9：输入 1000 件即时显示需 X 小时）
     * query：?qty=1000&capacity_per_hour=380&mold_change_min=30
     */
    public function previewCapacity(Request $request): JsonResponse
    {
        $request->validate([
            'qty' => ['required', 'integer', 'min:1'],
            'capacity_per_hour' => ['nullable', 'numeric', 'gt:0'],
            'mold_change_min' => ['nullable', 'integer', 'min:0'],
        ]);

        return $this->ok($this->routeService->previewCapacity(
            (int) $request->input('qty'),
            $request->input('capacity_per_hour') !== null ? (float) $request->input('capacity_per_hour') : null,
            $request->input('mold_change_min') !== null ? (int) $request->input('mold_change_min') : null,
        ));
    }
}
