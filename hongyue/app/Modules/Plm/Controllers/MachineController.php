<?php

namespace App\Modules\Plm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Modules\Plm\Requests\MachineRequest;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use App\Modules\Scheduling\Services\ScheduleCapacityRecalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 机台档案控制器（验收 A5：CRUD + 停用）
 */
class MachineController extends Controller
{
    /**
     * 机台列表（分页 + 过滤 type/workshop/status）
     */
    public function index(Request $request): JsonResponse
    {
        $query = Machine::query()
            ->with('workshop:id,name')
            ->when($request->input('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->input('workshop'), fn ($q, $workshop) => $q->whereHas('workshop', fn ($w) => $w->where('code', $workshop)))
            ->when($request->input('workshop_id'), fn ($q, $workshopId) => $q->where('workshop_id', (int) $workshopId))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('is_active') !== null, fn ($q, $active) => $q->where('is_active', (int) $active))
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(fn ($w) => $w->where('code', 'like', "%{$keyword}%")->orWhere('name', 'like', "%{$keyword}%"));
            })
            ->orderBy('code');

        if ($request->boolean('all')) {
            // 下拉选择场景：返回全量
            return $this->ok($query->get());
        }

        $perPage = min((int) $request->input('per_page', 15), 100);

        return $this->ok($query->paginate($perPage));
    }

    /**
     * 机台详情
     */
    public function show(int $id): JsonResponse
    {
        $machine = Machine::with('molds:id,code,name')->findOrFail($id);

        return $this->ok($machine);
    }

    /**
     * 新增机台
     */
    public function store(MachineRequest $request): JsonResponse
    {
        $machine = Machine::create($request->validated());

        return $this->ok($machine, '机台创建成功', 201);
    }

    /**
     * 更新机台
     *
     * 排程联动（QA P2）：停用机台（is_active=0）前检查 task_schedules 引用，
     * 存在已排程任务时 409 提示，避免悬空排程。
     */
    public function update(MachineRequest $request, int $id): JsonResponse
    {
        $machine = Machine::findOrFail($id);

        $data = $request->validated();
        $isBeingDeactivated = isset($data['is_active'])
            && (int) $data['is_active'] === 0
            && (int) $machine->is_active === 1;

        if ($isBeingDeactivated) {
            $scheduleCount = \App\Models\TaskSchedule::where('machine_id', $machine->id)->count();
            if ($scheduleCount > 0) {
                return $this->scheduleRefConflict($machine, $scheduleCount);
            }
        }

        // 编辑主数据与重算排程解耦：保存不能因为已有开工冲突失败；是否重算由页面明确触发。
        $machine->update($data);

        return $this->ok($machine->fresh(), '机台更新成功；如需让新产能作用于未开工排程，请点击“重算排程”');
    }

    /**
     * 按当前机台/产线产能重算排程。
     *
     * 只有明确点击重算时才执行；服务内部仍保护已开工、已完工和已有报工事实。
     */
    public function recalculateSchedules(int $id): JsonResponse
    {
        $machine = Machine::findOrFail($id);

        try {
            $result = app(ScheduleCapacityRecalculationService::class)->recalculateForMachine($machine->fresh());
        } catch (ScheduleException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409;

            return response()->json([
                'code' => $status,
                'message' => $e->getMessage(),
                'data' => ['errors' => $e->errors()],
            ], $status);
        }

        return $this->ok($result, "已从修改当天起重算 {$result['task_count']} 个未完工工单排程");
    }

    /**
     * 删除机台（软删除）
     *
     * 引用保护：被模具（mold_machines）关联的机台不可删除，返回 409 并附关联模具清单；
     * 存在排程（task_schedules.machine_id）引用同样 409（QA P2 联动），避免孤儿排程。
     */
    public function destroy(int $id): JsonResponse
    {
        $machine = Machine::findOrFail($id);

        // 排程引用保护（QA P2）：存在已排程任务时不可删除
        $scheduleCount = \App\Models\TaskSchedule::where('machine_id', $machine->id)->count();
        if ($scheduleCount > 0) {
            return $this->scheduleRefConflict($machine, $scheduleCount);
        }

        // pivot 连表查询需表前缀限定，避免与关联表同名列歧义
        $molds = $machine->molds()->get(['molds.id', 'molds.code', 'molds.name']);
        if ($molds->isNotEmpty()) {
            return response()->json([
                'code' => 409,
                'message' => '机台已被模具关联（适用机台），不可删除。请先在模具台账中解除关联',
                'data' => ['molds' => $molds],
            ], 409);
        }

        $machine->delete();

        return $this->ok(null, '机台已删除');
    }

    /**
     * 排程引用冲突响应（409，含工序数与机台信息）
     */
    private function scheduleRefConflict(Machine $machine, int $scheduleCount): JsonResponse
    {
        return response()->json([
            'code' => 409,
            'message' => "机台 {$machine->code} 存在 {$scheduleCount} 道已排程工序引用，不可停用/删除。请先解锁并重排这些任务，避免悬空排程",
            'data' => ['machine' => ['id' => $machine->id, 'code' => $machine->code], 'schedule_count' => $scheduleCount],
        ], 409);
    }
}
