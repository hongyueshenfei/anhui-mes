<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Machine;
use App\Models\Mold;
use App\Models\PlmRoute;
use App\Models\ProdTask;
use App\Models\Product;
use App\Models\RouteOp;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Plm\Services\CapacityCalcService;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use App\Modules\System\Services\ConfigService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * 排产引擎（共享纯计算内核）
 *
 * 职责：
 * - 产品-模具-机台三匹配校验（D7：保存前强制校验，不合格拒绝）+ 吨位硬约束（APS一期）
 * - 工序链构建：标准产能 + 换模时间（矩阵>工序>机台，APS一期）+ 班制日历 → 自动算结束时间（B2）
 * - 冲突检测（同机台/同模具重叠）
 * - 级联顺延 sweep（插单/回滚后消除资源重叠）
 *
 * 内部工作副本（work copy）行结构约定：
 * [
 *   'key' => 'db:<id>' | 'new:<i>', 'id' => int|null, 'task_id', 'seq', 'route_op_id', 'op_type',
 *   'machine_id', 'mold_id', 'plan_start' => Carbon, 'plan_end' => Carbon,
 *   'qty', 'status', 'source', 'is_interrupted', 'done_qty', 'is_overlap', 'note',
 *   'duration' => int(秒), 'capacity' => float, 'mold_change' => int(分), 'shift_mode', 'periods',
 *   'task_no' => string, 'task_locked' => bool, 'order_id' => int|null
 * ]
 */
class ScheduleEngineService
{
    /** 不同任务在同一机台/产线切换时的固定安全间隔（分钟） */
    public const TASK_SWITCH_GAP_MIN = 30;
    /** 人工线工序产能兜底（route_op.capacity_per_hour 为空时） */
    public const LINE_DEFAULT_CAPACITY = 200;

    /** 成型工序类型 */
    public const MOLDING_OPS = [RouteOp::OP_MOLDING_INJECTION, RouteOp::OP_MOLDING_BLOW];

    public function __construct(
        private readonly ChangeoverService $changeoverService = new ChangeoverService(),
    ) {
    }

    /**
     * 加载任务的有效工艺路线（已锁定优先，否则产品已发布）
     *
     * @throws ScheduleException 无已发布工艺
     */
    public function resolveRoute(ProdTask $task): PlmRoute
    {
        if ($task->route_id) {
            $route = PlmRoute::with('ops')->find($task->route_id);
            if ($route && $route->status === PlmRoute::STATUS_PUBLISHED) {
                return $route;
            }
            if ($route) {
                throw new ScheduleException("任务 {$task->task_no} 锁定工艺未发布，无法排产", [
                    ['code' => 'route_not_published', 'message' => '工艺路线未发布', 'task_id' => $task->id, 'task_no' => $task->task_no],
                ]);
            }
        }

        $route = $task->product->getPublishedRoute();

        if (! $route) {
            throw new ScheduleException("产品 {$task->product->code} 无已发布工艺路线，无法排产", [
                ['code' => 'no_published_route', 'message' => '产品无已发布工艺路线', 'task_id' => $task->id, 'task_no' => $task->task_no],
            ]);
        }

        return $route->load('ops');
    }

    /**
     * 确定排产模具：显式 moldId > 任务已选 > 产品默认
     *
     * @throws ScheduleException 无可用模具
     */
    public function resolveMold(ProdTask $task, ?int $moldId): int
    {
        $candidate = $moldId ?? $task->mold_id ?? $task->product->default_mold_id;

        if (! $candidate) {
            throw new ScheduleException("任务 {$task->task_no} 未指定模具且产品无默认模具", [
                ['code' => 'no_mold', 'message' => '任务未指定模具且产品无默认模具', 'task_id' => $task->id, 'task_no' => $task->task_no],
            ]);
        }

        return (int) $candidate;
    }

    /**
     * 产品-模具-机台三匹配校验（D7 强制项，force 也不豁免）+ 资源强对应（客户确认）
     *
     * 资源强对应：
     * - 成型工序 → 放置行必须是成型机台行（injection/blow），跨类型（如拖到产线行）409 resource_type_mismatch
     * - 纯产线任务（无成型工序）→ 放置行必须是产线行，且首个产线工序资源类型与产线一致
     * - 纯产线任务无模具，跳过三匹配的模具相关校验
     *
     * @param  int|null  $moldId  请求指定模具（null=自动取任务/产品默认）
     * @return int 最终确定的模具 id（纯产线任务返回 0）
     * @throws ScheduleException 任一匹配不通过
     */
    public function validateMatching(ProdTask $task, int $machineId, ?int $moldId): int
    {
        $errors = [];

        $machine = Machine::find($machineId);
        if (! $machine || ! $machine->is_active || $machine->status !== Machine::STATUS_RUNNING) {
            $statusLabel = $machine?->status_label ?? '不存在';
            throw new ScheduleException("机台不存在、已停用或当前不可用", [
                [
                    'code' => 'machine_inactive',
                    'message' => $machine
                        ? "机台 {$machine->code} 当前状态为 {$statusLabel}，不可排产"
                        : '机台不存在，无法排产',
                    'machine_id' => $machineId,
                    'machine_code' => $machine?->code,
                    'machine_status' => $machine?->status,
                    'task_id' => $task->id,
                ],
            ]);
        }

        $route = null;
        $routeError = null;
        try {
            $route = $this->resolveRoute($task);
        } catch (ScheduleException $e) {
            $routeError = $e;
        }

        if ($route === null) {
            // 无已发布工艺路线：保持原三匹配语义——模具缺失优先报 no_mold（TripleMatchTest 依赖），否则报无工艺
            $this->resolveMold($task, $moldId);

            throw $routeError;
        }

        $ops = $this->effectiveRouteOps($task, $route);

        // 资源强对应：放置行资源类型必须与工序目标资源类型一致（跨类型 409）。
        // 先收集，不立即中断，后续三匹配可以一次返回“资源类型 + 模具/机台”全部原因。
        // 这样用户能明确知道为什么不能拖入，也兼容历史测试/调用方依赖的多项错误码。
        $errors = array_merge($errors, $this->validateResourcePlacement($task, $machine, $route, $ops));

        // 工艺车间也是资源匹配条件：不能只校验注塑/吹塑类型正确，
        // 再把任务排到另一个生产车间的同类型机台。
        foreach ($ops as $op) {
            if (! in_array($op->op_type, self::MOLDING_OPS, true)
                || (int) ($op->workshop_id ?? 0) <= 0
                || (int) $machine->workshop_id === (int) $op->workshop_id) {
                continue;
            }
            $errors[] = [
                'code' => 'machine_workshop_mismatch',
                'message' => "工序 {$op->op_name} 需要车间 {$op->workshop_id}，与机台 {$machine->code} 所属车间不一致",
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'op_name' => $op->op_name,
                'machine_id' => $machine->id,
                'machine_workshop_id' => $machine->workshop_id,
                'operation_workshop_id' => $op->workshop_id,
            ];
        }

        // 吨位硬约束（APS一期）：机台锁模力必须 ≥ 产品所需最小锁模力
        // 任一侧为空则跳过（向后兼容存量数据/产线无锁模力场景）
        $requiredTonnage = $task->product->required_tonnage;
        if ($machine->tonnage !== null && $requiredTonnage !== null
            && (int) $machine->tonnage < (int) $requiredTonnage) {
            $errors[] = [
                'code' => 'tonnage_mismatch',
                'message' => "机台 {$machine->code} 锁模力 {$machine->tonnage} 吨，低于产品所需 {$requiredTonnage} 吨",
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'machine_tonnage' => (int) $machine->tonnage,
                'required_tonnage' => (int) $requiredTonnage,
            ];
        }

        // 纯产线任务（无成型工序）：无模具，跳过三匹配的模具相关校验
        $hasMolding = $ops->contains(fn ($op) => in_array($op->op_type, self::MOLDING_OPS, true));
        if (! $hasMolding) {
            if ($errors !== []) {
                throw new ScheduleException('吨位校验未通过', $errors);
            }

            return 0;
        }

        // 1. 模具必须可取
        try {
            $moldId = $this->resolveMold($task, $moldId);
        } catch (ScheduleException $e) {
            throw $e;
        }

        $mold = Mold::find($moldId);
        if (! $mold) {
            $errors[] = ['code' => 'mold_not_found', 'message' => "模具 {$moldId} 不存在", 'task_id' => $task->id, 'task_no' => $task->task_no];
        }

        if ($mold) {
            if (in_array($mold->status, [Mold::STATUS_REPAIR, Mold::STATUS_SCRAPPED], true)) {
                $errors[] = [
                    'code' => 'mold_unavailable',
                    'message' => "模具 {$mold->code} 当前状态为 {$mold->status_label}，不可排产",
                    'task_id' => $task->id,
                    'task_no' => $task->task_no,
                    'mold_id' => $mold->id,
                    'mold_code' => $mold->code,
                    'mold_status' => $mold->status,
                ];
            }

            // 1. 产品-模具：模具关联产品（mold_product 或产品默认模具）
            $productLinked = $mold->products()->whereKey($task->product_id)->exists()
                || (int) $task->product->default_mold_id === $mold->id;
            if (! $productLinked) {
                $errors[] = ['code' => 'mold_product_mismatch', 'message' => "模具 {$mold->code} 不适用于产品 {$task->product->code}", 'task_id' => $task->id, 'task_no' => $task->task_no];
            }

            // 2. 模具-机台：模具适用机台清单
            if (! $mold->isCompatibleWithMachine($machineId)) {
                $errors[] = ['code' => 'mold_machine_mismatch', 'message' => "模具 {$mold->code} 不适用于机台 {$machine->code}", 'task_id' => $task->id, 'task_no' => $task->task_no];
            }

            // 3. 机台-工艺：成型工序机台类型匹配（同类别子类型不匹配，如 injection vs blow）
            foreach ($ops as $op) {
                if (! in_array($op->op_type, self::MOLDING_OPS, true)) {
                    continue;
                }
                if ($op->machine_type !== null && $op->machine_type !== $machine->type) {
                    $errors[] = ['code' => 'machine_type_mismatch', 'message' => "工序 {$op->op_name} 需机台类型 {$op->machine_type}，与机台 {$machine->code}({$machine->type}) 不匹配", 'task_id' => $task->id, 'task_no' => $task->task_no];
                }
            }
        }

        if ($errors !== []) {
            throw new ScheduleException('产品-模具-机台三匹配校验未通过', $errors);
        }

        return $moldId;
    }

    /**
     * 校验单道工序调整时的目标资源。
     *
     * 单工序移动不能复用 validateMatching() 的“任务首工序放置”语义，
     * 否则把效果/包装工序移动到自己的产线时，会被误判为整单放置资源。
     */
    public function validateOperationPlacement(
        ProdTask $task,
        RouteOp $op,
        Machine $machine,
        PlmRoute $route,
        ?\Illuminate\Support\Collection $ops = null,
    ): void {
        if (! $machine->is_active || $machine->status !== Machine::STATUS_RUNNING) {
            throw new ScheduleException('目标资源当前不可用，无法调整工序', [[
                'code' => 'machine_inactive',
                'message' => "目标资源 {$machine->code} 当前不可用",
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'op_name' => $op->op_name,
                'machine_id' => $machine->id,
                'machine_status' => $machine->status,
            ]]);
        }

        $resourceType = $op->resourceType();
        if ($resourceType === null) {
            throw new ScheduleException("工序 {$op->op_name} 未配置资源类型，无法调整", [[
                'code' => 'resource_type_not_configured',
                'message' => "工序 {$op->op_name} 未配置资源类型",
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'op_name' => $op->op_name,
            ]]);
        }

        if ($machine->type !== $resourceType) {
            throw new ScheduleException("工序 {$op->op_name} 与目标资源类型不匹配，无法调整", [[
                'code' => 'resource_type_mismatch',
                'message' => "工序 {$op->op_name} 需要 {$op->resource_type_label}，目标资源 {$machine->code} 为 {$machine->type_label}",
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'op_name' => $op->op_name,
                'op_type' => $op->op_type,
                'resource_type' => $resourceType,
                'target_resource' => [
                    'id' => $machine->id,
                    'code' => $machine->code,
                    'type' => $machine->type,
                    'type_label' => $machine->type_label,
                ],
                'resources' => $this->resourceList($route, $ops),
            ]]);
        }

        if ((int) ($op->workshop_id ?? 0) > 0 && (int) $machine->workshop_id !== (int) $op->workshop_id) {
            $code = in_array($op->op_type, self::MOLDING_OPS, true)
                ? 'machine_workshop_mismatch'
                : 'line_resource_workshop_mismatch';
            throw new ScheduleException("工序 {$op->op_name} 与目标资源所属车间不匹配，无法调整", [[
                'code' => $code,
                'message' => "工序 {$op->op_name} 需要车间 {$op->workshop_id}，目标资源 {$machine->code} 所属车间不一致",
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'op_name' => $op->op_name,
                'resource_type' => $resourceType,
                'machine_id' => $machine->id,
                'machine_workshop_id' => $machine->workshop_id,
                'operation_workshop_id' => $op->workshop_id,
            ]]);
        }

        if (in_array($op->op_type, self::MOLDING_OPS, true)
            && $op->machine_type !== null
            && $op->machine_type !== $machine->type) {
            throw new ScheduleException("工序 {$op->op_name} 与目标机台类型不匹配，无法调整", [[
                'code' => 'machine_type_mismatch',
                'message' => "工序 {$op->op_name} 需机台类型 {$op->machine_type}，目标资源 {$machine->code} 为 {$machine->type}",
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'op_name' => $op->op_name,
                'resource_type' => $resourceType,
            ]]);
        }

        if ((float) ($machine->capacity_per_hour ?? 0) <= 0) {
            throw new ScheduleException("目标资源 {$machine->code} 未配置产能，无法调整", [[
                'code' => 'capacity_not_configured',
                'message' => "目标资源 {$machine->code} 未配置有效产能",
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'op_name' => $op->op_name,
            ]]);
        }
    }

    /**
     * 资源强对应校验：放置行资源类型必须与工序目标资源类型一致（不能跨也不能少）
     *
     * @return list<array> 资源不匹配错误项；空数组表示放置资源匹配
     */
    public function validateResourcePlacement(
        ProdTask $task,
        Machine $machine,
        PlmRoute $route,
        ?\Illuminate\Support\Collection $ops = null,
    ): array
    {
        $ops ??= $this->effectiveRouteOps($task, $route);
        $firstMolding = $ops->first(fn ($op) => in_array($op->op_type, self::MOLDING_OPS, true));
        $firstLine = $ops->first(fn ($op) => ! in_array($op->op_type, self::MOLDING_OPS, true));

        if ($firstMolding !== null) {
            $errors = [];
            // 任务含成型工序 → 放置行必须是成型机台行
            if ($machine->isLine()) {
                $errors[] = $this->resourceMismatchError($task, $firstMolding, $machine, $route, $ops);
            } elseif ($firstMolding->resourceType() !== null && $machine->type !== $firstMolding->resourceType()) {
                // 注塑成型只能落注塑机，吹塑成型只能落吹塑机；不能只校验“是不是成型机”。
                $errors[] = $this->resourceMismatchError($task, $firstMolding, $machine, $route, $ops);
            }

            return $errors;
        }

        // 纯产线任务（无成型工序）→ 放置行必须是产线行
        if ($firstLine === null) {
            return [[
                'code' => 'no_ops',
                'message' => '工艺路线无工序',
                'task_id' => $task->id,
                'task_no' => $task->task_no,
            ]];
        }

        if (! $machine->isLine()) {
            return [$this->resourceMismatchError($task, $firstLine, $machine, $route, $ops)];
        }

        if ($firstLine->resourceType() !== $machine->type) {
            return [$this->resourceMismatchError($task, $firstLine, $machine, $route, $ops)];
        }

        return [];
    }

    /**
     * 资源强对应错误项（含工序名与资源清单）
     */
    private function resourceMismatchError(
        ProdTask $task,
        RouteOp $op,
        Machine $machine,
        PlmRoute $route,
        ?\Illuminate\Support\Collection $ops = null,
    ): array
    {
        return [
            'code' => 'resource_type_mismatch',
            'message' => "工序 {$op->op_name} 需资源类型 {$op->resource_type_label}，与放置资源 {$machine->code}({$machine->type_label}) 不匹配",
            'task_id' => $task->id,
            'task_no' => $task->task_no,
            'op_name' => $op->op_name,
            'op_type' => $op->op_type,
            'resource_type' => $op->resourceType(),
            'target_resource' => [
                'id' => $machine->id,
                'code' => $machine->code,
                'type' => $machine->type,
                'type_label' => $machine->type_label,
            ],
            'resources' => $this->resourceList($route, $ops),
        ];
    }

    /**
     * 工艺路线全部工序的资源类型清单（错误响应附资源清单）
     *
     * @return list<array>
     */
    public function resourceList(PlmRoute $route, ?\Illuminate\Support\Collection $ops = null): array
    {
        return ($ops ?? $route->ops)->map(fn (RouteOp $op) => [
            'seq' => (int) $op->seq,
            'op_name' => $op->op_name,
            'op_type' => $op->op_type,
            'resource_type' => $op->resourceType(),
        ])->values()->all();
    }

    /**
     * 任务实际生效的工序对象集合：订单级快照优先，标准路线作为属性兜底。
     *
     * route_ops_override 只保存确认阶段允许编辑的字段，不保存 route_op_id、
     * machine_type、班制和换模参数；这些字段从同类型标准工序继承。若新增了
     * 标准路线不存在的工序，则按内置工序类型映射补齐必要的资源/班制语义。
     */
    public function effectiveRouteOps(ProdTask $task, PlmRoute $route): \Illuminate\Support\Collection
    {
        if (! $task->hasRouteOpsOverride()) {
            return $route->ops;
        }

        $standardByType = $route->ops->groupBy('op_type');

        return collect($task->effectiveOps())
            ->values()
            ->map(function (array $override, int $index) use ($route, $standardByType) {
                $opType = (string) ($override['op_type'] ?? '');
                $base = $standardByType->get($opType)?->first() ?? $route->ops->get($index);
                $baseType = (string) ($base?->op_type ?? '');
                $op = $base ? clone $base : new RouteOp();

                $op->id = (int) ($base?->id ?? 0);
                $op->route_id = (int) $route->id;
                $op->seq = (int) ($override['seq'] ?? ($index + 1));
                $op->op_type = $opType;
                // 兼容早期/简化快照：字段未保存时继承标准路线；字段显式为
                // null 时保留用户的清空动作，不能用 ?? 把“缺失”和“清空”混为一谈。
                $op->op_name = (string) ($override['op_name'] ?? $base?->op_name ?? $opType);
                $op->workshop_id = array_key_exists('workshop_id', $override)
                    ? $override['workshop_id']
                    : $base?->workshop_id;
                $op->default_machine_id = array_key_exists('machine_id', $override)
                    ? $override['machine_id']
                    : $base?->default_machine_id;
                $op->default_line_id = array_key_exists('line_id', $override)
                    ? $override['line_id']
                    : $base?->default_line_id;
                $op->std_hours = array_key_exists('std_hours', $override)
                    ? $override['std_hours']
                    : $base?->std_hours;
                $op->owner_id = array_key_exists('owner_id', $override)
                    ? $override['owner_id']
                    : $base?->owner_id;
                $op->remark = array_key_exists('remark', $override)
                    ? $override['remark']
                    : $base?->remark;

                $isMolding = in_array($opType, self::MOLDING_OPS, true);
                $op->machine_type = $isMolding ? RouteOp::resourceTypeFor($opType) : null;
                $op->shift_mode = $baseType === $opType && $op->shift_mode
                    ? $op->shift_mode
                    : ($isMolding ? 'two_shift' : 'day');
                $op->mold_change_min = $isMolding
                    ? ($baseType === $opType ? ($op->mold_change_min ?? 0) : 0)
                    : null;

                return $op;
            });
    }

    /**
     * 构建工序链（work copy 行序列）——自动计算结束时间（标准产能+换模+班制）
     *
     * 资源强对应（客户确认）：每道工序排到对应资源行——
     * - 成型工序 → 放置机台（machine_id）
     * - 喷漆 → 喷漆产线行（P-01，machine_id=产线）
     * - 效果 → 效果产线行（P-02，machine_id=产线）
     * - 包装 → 包装产线行（P-03，machine_id=产线）
     *
     * @return list<array>
     */
    public function buildChain(
        ProdTask $task,
        int $machineId,
        int $moldId,
        CarbonInterface $start,
        string $source,
        array $plannedRows = [],
    ): array
    {
        $route = $this->resolveRoute($task);
        $placementMachine = Machine::findOrFail($machineId);

        // 订单级工序数量（工艺确认的工序数量 = 该步骤排产/报工的数量依据；未定制时全按任务数量）
        $opQtyBySeq = [];
        foreach ($task->effectiveOps() as $op) {
            $seq = (int) ($op['seq'] ?? 0);
            if ($seq > 0) {
                $opQtyBySeq[$seq] = max(1, (int) ($op['qty'] ?? $task->qty));
            }
        }

        $rows = [];
        $cursor = Carbon::parse($start);

        foreach ($this->effectiveRouteOps($task, $route) as $op) {
            $opQty = $opQtyBySeq[(int) $op->seq] ?? (int) $task->qty;
            $isMolding = in_array($op->op_type, self::MOLDING_OPS, true);
            $resourceType = $op->resourceType();

            // 资源上下文（顺排/倒排共用）：资源行 + 产能 + 班制
            $context = $this->opResourceContext($task, $op, $placementMachine);
            $resMachine = $context['machine'];
            $resMachineId = (int) $resMachine->id;
            $capacity = $context['capacity'];
            $shiftMode = $context['shift_mode'];
            $gapInserted = false;

            // 首道工序使用计划员拖入的时间作为锚点；后续工序不再机械地紧贴前序工序，
            // 而是在满足工序先后关系的前提下，寻找对应机台/产线的最早可用空档。
            // 例如成型结束后效果线正在加工其他任务，当前任务可以等待到效果线空闲时再开始。
            $resourceRows = array_merge($plannedRows, $rows);
            if ($rows !== []) {
                [$produceStart, $end, $moldChange, $duration, $gapInserted] = $this->fitOperationIntoResourceGap(
                    $cursor,
                    $task,
                    $op,
                    $resMachine,
                    $moldId,
                    $opQty,
                    (float) $capacity,
                    $shiftMode,
                    $resourceRows,
                );
            } else {
                $produceStart = $this->effectiveStart($cursor, $shiftMode, ConfigService::shiftPeriods($shiftMode));

                // 换模时间解析（APS一期）：成型工序先查机台上紧邻前序模具，再按 矩阵>工序>机台 取值；
                // 命中禁止切换（如黑料→白料）抛 changeover_forbidden。人工线无换模恒为 0。
                if ($isMolding) {
                    $fromMoldId = $this->changeoverService->previousMoldOnMachine(
                        $resMachineId,
                        $produceStart,
                        $resourceRows,
                        (int) $task->id,
                    );
                    $moldChange = $this->changeoverService->resolve($fromMoldId, $moldId, $op, $resMachine);
                } else {
                    $moldChange = 0;
                }

                $duration = CapacityCalcService::estimateSeconds($opQty, (float) $capacity, $moldChange);
                $end = CapacityCalcService::estimateEndTime($produceStart, $opQty, (float) $capacity, $moldChange, $shiftMode, ConfigService::shiftPeriods($shiftMode));
            }

            $rows[] = [
                'key' => 'new:'.count($rows),
                'id' => null,
                'task_id' => (int) $task->id,
                'task_no' => $task->task_no,
                'task_locked' => (bool) $task->is_locked,
                'order_id' => (int) $task->line?->order_id,
                'seq' => (int) $op->seq,
                'route_op_id' => (int) $op->id,
                'op_type' => $op->op_type,
                'op_name' => $op->op_name,
                'resource_type' => $resourceType,
                'machine_id' => $resMachineId,
                'mold_id' => $isMolding ? $moldId : null,
                'plan_start' => $produceStart->copy(),
                'plan_end' => $end->copy(),
                'qty' => $opQty,
                'status' => 'pending',
                'source' => $source,
                'is_interrupted' => 0,
                'done_qty' => 0,
                'is_overlap' => 0,
                'note' => null,
                'duration' => $duration,
                'capacity' => (float) $capacity,
                'mold_change' => $moldChange,
                'shift_mode' => $shiftMode,
                'periods' => ConfigService::shiftPeriods($shiftMode),
                // 仅供算法接受结果说明使用，不落库；表示后续工序曾因资源占用插入空档。
                'gap_inserted' => $gapInserted,
            ];

            $cursor = $end->copy();
        }

        // 工序完整性：任务的所有工序都必须有排程（缺工序拒绝，不能少）
        if ($rows === []) {
            throw new ScheduleException("任务 {$task->task_no} 工艺路线无工序，无法排产", [
                ['code' => 'no_ops', 'message' => '工艺路线无工序', 'task_id' => $task->id, 'task_no' => $task->task_no],
            ]);
        }

        return $rows;
    }

    /**
     * 将后续工序放入资源最早可用的空档。
     *
     * 首道工序由计划员明确选择位置，不在这里自动挪动；本方法只服务于同一任务的后续工序。
     * 资源冲突时向后寻找，不改动已经存在的其他任务，也不把任务整条链强行压成连续时间。
     *
     * @return array{0: Carbon, 1: Carbon, 2: int, 3: int, 4: bool}
     */
    private function fitOperationIntoResourceGap(
        CarbonInterface $earliestStart,
        ProdTask $task,
        RouteOp $op,
        Machine $resourceMachine,
        int $moldId,
        int $quantity,
        float $capacity,
        string $shiftMode,
        array $plannedRows,
    ): array {
        $periods = ConfigService::shiftPeriods($shiftMode);
        $isMolding = in_array($op->op_type, self::MOLDING_OPS, true);
        $candidate = Carbon::parse($earliestStart);
        $gapInserted = false;

        // 资源行数量有限，正常情况下几轮即可找到空档；上限用于防止异常数据造成死循环。
        for ($attempt = 0; $attempt < 200; $attempt++) {
            $candidate = $this->effectiveStart($candidate, $shiftMode, $periods);

            // 换模时间取决于候选开始时刻之前的同机台模具，因此每次跳过一个占用区间后都重新计算。
            if ($isMolding) {
                $fromMoldId = $this->changeoverService->previousMoldOnMachine(
                    (int) $resourceMachine->id,
                    $candidate,
                    $plannedRows,
                    (int) $task->id,
                );
                $moldChange = $this->changeoverService->resolve($fromMoldId, $moldId, $op, $resourceMachine);
            } else {
                $moldChange = 0;
            }

            $duration = CapacityCalcService::estimateSeconds($quantity, $capacity, $moldChange);
            $end = CapacityCalcService::estimateEndTime(
                $candidate,
                $quantity,
                $capacity,
                $moldChange,
                $shiftMode,
                $periods,
            );

            $nextStart = null;
            foreach ($plannedRows as $existing) {
                if ((int) ($existing['task_id'] ?? 0) === (int) $task->id
                    || (int) ($existing['is_interrupted'] ?? 0) === 1) {
                    continue;
                }

                $existingInterval = $this->effectiveOccupancyInterval($existing);
                if ($existingInterval === null) {
                    continue;
                }

                [$existingStart, $existingEnd] = $existingInterval;
                $sameMachine = (int) ($existing['machine_id'] ?? 0) === (int) $resourceMachine->id;
                $sameMold = $isMolding
                    && $moldId > 0
                    && (int) ($existing['mold_id'] ?? 0) === $moldId;

                if (! $sameMachine && ! $sameMold) {
                    continue;
                }

                // 与保存时同一套资源占用口径：同机台还要留 30 分钟切换间隔，模具只禁止并发。
                $gapMinutes = $sameMachine ? self::TASK_SWITCH_GAP_MIN : 0;
                $candidateRequiredEnd = $end->copy()->addMinutes($gapMinutes);
                $existingRequiredEnd = $existingEnd->copy()->addMinutes($gapMinutes);
                $overlap = $candidate->lt($existingRequiredEnd)
                    && $existingStart->lt($candidateRequiredEnd);

                if (! $overlap) {
                    continue;
                }

                $requiredStart = $existingEnd->copy()->addMinutes($gapMinutes);
                if ($nextStart === null || $requiredStart->gt($nextStart)) {
                    $nextStart = $requiredStart;
                }
            }

            if ($nextStart === null) {
                return [$candidate, $end, $moldChange, $duration, $gapInserted];
            }

            $gapInserted = true;
            $candidate = $nextStart;
        }

        // 理论上不会到达这里；若存量数据异常到无法找到空档，保留候选值，
        // 由统一冲突校验给出具体任务/工序冲突，而不是静默写入重叠排程。
        return [$candidate, $end, $moldChange, $duration, $gapInserted];
    }

    /**
     * 解析单道工序的资源上下文（顺排 buildChain 与倒排 BackwardScheduleService 共用）
     *
     * - 成型工序 → 放置机台（产能：工序 > 机台；班制：工序 > 机台 > two_shift）
     * - 人工线工序 → 优先使用工艺已绑定的产线；未绑定时取对应资源类型的首条可用产线
     *   （产能兜底 LINE_DEFAULT_CAPACITY；班制兜底 day）
     *
     * @return array{machine: Machine, capacity: float, shift_mode: string}
     *
     * @throws ScheduleException 资源类型未配置 / 产线缺失 / 产能未配置
     */
    public function opResourceContext(ProdTask $task, RouteOp $op, Machine $placementMachine): array
    {
        $isMolding = in_array($op->op_type, self::MOLDING_OPS, true);

        if ($isMolding) {
            $machine = $placementMachine;
            if ((int) ($op->workshop_id ?? 0) > 0
                && (int) $machine->workshop_id !== (int) $op->workshop_id) {
                throw new ScheduleException("工序 {$op->op_name} 与机台所属车间不匹配，无法排产", [[
                    'code' => 'machine_workshop_mismatch',
                    'message' => "工序 {$op->op_name} 需要车间 {$op->workshop_id}，与机台 {$machine->code} 所属车间不一致",
                    'task_id' => $task->id,
                    'task_no' => $task->task_no,
                    'op_name' => $op->op_name,
                    'machine_id' => $machine->id,
                    'machine_workshop_id' => $machine->workshop_id,
                    'operation_workshop_id' => $op->workshop_id,
                ]]);
            }
            $capacity = (float) ($op->capacity_per_hour ?: $machine->capacity_per_hour);
            $shiftMode = $op->shift_mode ?: ($machine->shift_mode ?: 'two_shift');
        } else {
            $resourceType = $op->resourceType();
            if ($resourceType === null) {
                throw new ScheduleException("工序 {$op->op_name} 未配置资源类型，无法排产", [
                    ['code' => 'resource_type_not_configured', 'message' => "工序 {$op->op_name} 未配置资源类型", 'task_id' => $task->id, 'task_no' => $task->task_no, 'op_name' => $op->op_name],
                ]);
            }
            $configuredLineId = (int) ($op->default_line_id ?? 0);
            if ($configuredLineId > 0) {
                // 工艺明确绑定产线时不能静默换到同类型的另一条线，否则
                // 排产、报工和现场实际资源会失去一一对应关系。
                $line = Machine::find($configuredLineId);
                if (! $line) {
                    throw new ScheduleException("工序 {$op->op_name} 绑定的产线不存在，无法排产", [
                        [
                            'code' => 'line_resource_not_found',
                            'message' => "工序 {$op->op_name} 绑定的产线不存在",
                            'task_id' => $task->id,
                            'task_no' => $task->task_no,
                            'op_name' => $op->op_name,
                            'resource_type' => $resourceType,
                            'line_id' => $configuredLineId,
                        ],
                    ]);
                }
                if ($line->type !== $resourceType) {
                    throw new ScheduleException("工序 {$op->op_name} 绑定产线类型不匹配，无法排产", [
                        [
                            'code' => 'line_resource_mismatch',
                            'message' => "工序 {$op->op_name} 需要 {$resourceType} 产线，当前绑定为 {$line->type}",
                            'task_id' => $task->id,
                            'task_no' => $task->task_no,
                            'op_name' => $op->op_name,
                            'resource_type' => $resourceType,
                            'line_id' => $line->id,
                            'line_type' => $line->type,
                        ],
                    ]);
                }
                if ((int) ($op->workshop_id ?? 0) > 0
                    && (int) $line->workshop_id !== (int) $op->workshop_id) {
                    throw new ScheduleException("工序 {$op->op_name} 绑定产线不属于工艺车间，无法排产", [
                        [
                            'code' => 'line_resource_workshop_mismatch',
                            'message' => "工序 {$op->op_name} 绑定产线不属于工艺配置车间",
                            'task_id' => $task->id,
                            'task_no' => $task->task_no,
                            'op_name' => $op->op_name,
                            'resource_type' => $resourceType,
                            'line_id' => $line->id,
                            'workshop_id' => $op->workshop_id,
                            'line_workshop_id' => $line->workshop_id,
                        ],
                    ]);
                }
                if (! $line->is_active || $line->status !== Machine::STATUS_RUNNING) {
                    throw new ScheduleException("工序 {$op->op_name} 绑定产线当前不可用，无法排产", [
                        [
                            'code' => 'line_resource_unavailable',
                            'message' => "工序 {$op->op_name} 绑定产线 {$line->code} 当前不可用",
                            'task_id' => $task->id,
                            'task_no' => $task->task_no,
                            'op_name' => $op->op_name,
                            'resource_type' => $resourceType,
                            'line_id' => $line->id,
                            'line_code' => $line->code,
                            'line_status' => $line->status,
                        ],
                    ]);
                }
            } else {
                $lineQuery = Machine::where('type', $resourceType)
                    ->where('is_active', 1)
                    ->where('status', Machine::STATUS_RUNNING);
                if ((int) ($op->workshop_id ?? 0) > 0) {
                    $lineQuery->where('workshop_id', (int) $op->workshop_id);
                }
                $line = $lineQuery->orderBy('id')->first();
            }
            if (! $line) {
                $workshopHint = (int) ($op->workshop_id ?? 0) > 0
                    ? "、车间 {$op->workshop_id}"
                    : '';
                throw new ScheduleException("工序 {$op->op_name} 无可用产线（{$resourceType}），无法排产", [
                    ['code' => 'line_resource_missing', 'message' => "工序 {$op->op_name} 需要 {$resourceType} 产线{$workshopHint}，但系统中无可用产线", 'task_id' => $task->id, 'task_no' => $task->task_no, 'op_name' => $op->op_name, 'resource_type' => $resourceType, 'workshop_id' => $op->workshop_id],
                ]);
            }
            $machine = $line;
            $capacity = (float) ($op->capacity_per_hour ?: ($line->capacity_per_hour ?: self::LINE_DEFAULT_CAPACITY));
            $shiftMode = $op->shift_mode ?: ($line->shift_mode ?: 'day');
        }

        if ($capacity <= 0) {
            throw new ScheduleException("工序 {$op->op_name} 未配置标准产能，无法排产", [
                ['code' => 'capacity_not_configured', 'message' => "工序 {$op->op_name} 未配置标准产能", 'task_id' => $task->id, 'task_no' => $task->task_no],
            ]);
        }

        return ['machine' => $machine, 'capacity' => $capacity, 'shift_mode' => $shiftMode];
    }

    /**
     * 对齐到班制内的第一个有效生产时刻（two_shift 24h 连续 → 原样返回）
     */
    public function effectiveStart(CarbonInterface $start, string $shiftMode, array $periods = []): CarbonInterface
    {
        if ($shiftMode === 'two_shift') {
            return Carbon::parse($start);
        }

        $period = $periods ?: ['start' => '08:00', 'end' => '17:00'];
        [$startHour, $startMin] = array_map('intval', explode(':', $period['start']));
        [$endHour, $endMin] = array_map('intval', explode(':', $period['end']));
        $workStart = $startHour * 3600 + $startMin * 60;
        $workEnd = $endHour * 3600 + $endMin * 60;

        $cursor = Carbon::parse($start);
        $dayStart = $cursor->copy()->startOfDay();
        $secondsIntoDay = $cursor->getTimestamp() - $dayStart->getTimestamp();

        if ($secondsIntoDay < $workStart) {
            return $dayStart->copy()->addSeconds($workStart);
        }
        if ($secondsIntoDay >= $workEnd) {
            return $dayStart->copy()->addDay()->addSeconds($workStart);
        }

        return $cursor;
    }

    /**
     * 现有排程 → 工作副本（含任务快照信息与产能参数，供顺延时班制重算）
     *
     * @return list<array>
     */
    public function loadWorkRows(?\Illuminate\Support\Collection $query = null): array
    {
        $rows = [];
        $schedules = (\App\Models\TaskSchedule::query())
            ->with(['task.line', 'task.product:id,code,name', 'machine'])
            ->orderBy('plan_start')
            ->get();
        $scheduleIds = $schedules->pluck('id')->map(fn ($id) => (int) $id)->all();
        $taskIds = $schedules->pluck('task_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $reportedScheduleIds = WorkReport::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->pluck('schedule_id')
            ->map(fn ($id) => (int) $id)
            ->flip();
        $taskLevelReportTaskIds = WorkReport::query()
            ->whereIn('task_id', $taskIds)
            ->whereNull('schedule_id')
            ->pluck('task_id')
            ->map(fn ($id) => (int) $id)
            ->flip();
        $lastSeqByTask = $schedules
            ->groupBy(fn ($schedule) => (int) $schedule->task_id)
            ->map(fn ($taskSchedules) => (int) $taskSchedules->max('seq'));

        foreach ($schedules as $schedule) {
            // 产能参数（顺延重算用）：排程快照字段优先，机台兜底
            $isMolding = in_array($schedule->op_type, self::MOLDING_OPS, true);
            if ($isMolding) {
                $capacity = $schedule->machine?->capacity_per_hour ?: 0;
                // 换模时间：优先排程留档值（APS换模矩阵解析结果），存量行无留档时机台默认兜底
                $moldChange = (int) ($schedule->changeover_minutes ?: ($schedule->machine?->mold_change_min ?? 0));
                $shiftMode = $schedule->machine?->shift_mode ?: 'two_shift';
            } else {
                // 人工线工序：产线行（machine）产能/班制
                $capacity = $schedule->machine?->capacity_per_hour ?: self::LINE_DEFAULT_CAPACITY;
                $moldChange = 0;
                $shiftMode = $schedule->machine?->shift_mode ?: 'day';
            }

            $rows[] = [
                'key' => 'db:'.$schedule->id,
                'id' => (int) $schedule->id,
                'task_id' => (int) $schedule->task_id,
                'task_no' => $schedule->task?->task_no ?? '',
                'task_locked' => (bool) ($schedule->task?->is_locked ?? false),
                'order_id' => (int) ($schedule->task?->line?->order_id ?? null),
                'seq' => (int) $schedule->seq,
                'route_op_id' => (int) $schedule->route_op_id,
                'op_type' => $schedule->op_type,
                'op_name' => $schedule->op_name ?? $schedule->op_type,
                'resource_type' => RouteOp::resourceTypeFor($schedule->op_type),
                'machine_id' => $schedule->machine_id ? (int) $schedule->machine_id : null,
                'mold_id' => $schedule->mold_id ? (int) $schedule->mold_id : null,
                'plan_start' => Carbon::parse($schedule->plan_start),
                'plan_end' => Carbon::parse($schedule->plan_end),
                'forecast_start' => $schedule->forecast_start ? Carbon::parse($schedule->forecast_start) : null,
                'forecast_end' => $schedule->forecast_end ? Carbon::parse($schedule->forecast_end) : null,
                'actual_start' => $schedule->actual_start ? Carbon::parse($schedule->actual_start) : null,
                'actual_end' => $schedule->actual_end ? Carbon::parse($schedule->actual_end) : null,
                // 存量数据可能已有报工流水但未补齐 actual/status；把它带入工作副本，
                // 让顺延、产能重算和冲突保护也遵守“实际数据不可被计划覆盖”。
                'has_report' => isset($reportedScheduleIds[(int) $schedule->id])
                    || (isset($taskLevelReportTaskIds[(int) $schedule->task_id])
                        && (int) $schedule->seq === (int) ($lastSeqByTask[(int) $schedule->task_id] ?? 0)),
                'is_frozen' => (int) $schedule->is_frozen,
                'changeover_minutes' => (int) $schedule->changeover_minutes,
                'task_status' => $schedule->task?->status,
                'task_is_locked' => (int) ($schedule->task?->is_locked ?? 0),
                'task_plan_start' => $schedule->task?->plan_start?->format('Y-m-d H:i:s'),
                'task_plan_end' => $schedule->task?->plan_end?->format('Y-m-d H:i:s'),
                'task_mold_id' => $schedule->task?->mold_id,
                'task_route_id' => $schedule->task?->route_id,
                'qty' => (int) $schedule->qty,
                'status' => $schedule->status,
                'source' => $schedule->source,
                'is_interrupted' => (int) $schedule->is_interrupted,
                'done_qty' => (int) $schedule->done_qty,
                'is_overlap' => (int) $schedule->is_overlap,
                'note' => $schedule->note,
                'duration' => max(60, (int) Carbon::parse($schedule->plan_end)->getTimestamp() - Carbon::parse($schedule->plan_start)->getTimestamp()),
                'capacity' => (float) $capacity,
                'mold_change' => $moldChange,
                'shift_mode' => $shiftMode,
                'periods' => ConfigService::shiftPeriods($shiftMode),
                'product_id' => (int) ($schedule->task?->product_id ?? 0),
                'product_code' => $schedule->task?->product?->code ?? '',
                'product_name' => $schedule->task?->product?->name ?? '',
            ];
        }

        return $rows;
    }

    /**
     * 冲突检测：新行 vs 现有行（同机台/同模具重叠）
     *
     * @param  list<array>  $newRows
     * @param  list<array>  $existingRows
     * @return list<array> 冲突清单
     */
    public function findConflicts(array $newRows, array $existingRows): array
    {
        $conflicts = [];
        $all = array_merge($newRows, $existingRows);

        // 新行之间也要检查（批量保存/插单多任务时）
        for ($i = 0; $i < count($newRows); $i++) {
            // 插单打断留档只用于展示历史实际片段，不再占用当前机台/模具资源。
            if ((int) ($newRows[$i]['is_interrupted'] ?? 0) === 1) {
                continue;
            }
            if ($this->effectiveOccupancyInterval($newRows[$i]) === null) {
                continue;
            }
            for ($j = 0; $j < count($all); $j++) {
                if ($i === $j && $all[$j]['key'] === $newRows[$i]['key']) {
                    continue;
                }
                if ((int) ($all[$j]['is_interrupted'] ?? 0) === 1) {
                    continue;
                }
                if ($this->effectiveOccupancyInterval($all[$j]) === null) {
                    continue;
                }
                if ($newRows[$i]['task_id'] === $all[$j]['task_id']) {
                    continue; // 同任务链内不冲突
                }
                $conflict = $this->pairConflict($newRows[$i], $all[$j]);
                if ($conflict) {
                    $conflicts[] = $conflict;
                }
            }
        }

        return $this->dedupeConflicts($conflicts);
    }

    /**
     * 两行是否同资源重叠（机台/模具互斥）
     */
    private function pairConflict(array $a, array $b): ?array
    {
        $aInterval = $this->effectiveOccupancyInterval($a);
        $bInterval = $this->effectiveOccupancyInterval($b);
        if ($aInterval === null || $bInterval === null) {
            return null;
        }
        [$aStart, $aEnd] = $aInterval;
        [$bStart, $bEnd] = $bInterval;
        $sameMachine = $a['machine_id'] !== null && $a['machine_id'] === $b['machine_id'];
        // 同机台/产线的切换间隔与前后传入顺序无关：
        // a 在 b 前时检查 b.start < a.end + 30；b 在 a 前时反向检查 a.start < b.end + 30。
        // 直接对两端都加安全间隔，避免仅检查“新行在前”导致反向漏判。
        $aRequiredEnd = $sameMachine ? $aEnd->copy()->addMinutes(self::TASK_SWITCH_GAP_MIN) : $aEnd;
        $bRequiredEnd = $sameMachine ? $bEnd->copy()->addMinutes(self::TASK_SWITCH_GAP_MIN) : $bEnd;
        $overlap = $aStart->lt($bRequiredEnd) && $bStart->lt($aRequiredEnd);

        if (! $overlap) {
            return null;
        }

        $resource = null;
        $reason = null;

        if ($a['machine_id'] !== null && $a['machine_id'] === $b['machine_id']) {
            $resource = 'machine:'.$a['machine_id'];
            $hasGap = $aEnd->lte($bStart) || $bEnd->lte($aStart);
            $reason = $hasGap ? '同机台任务切换间隔不足30分钟' : '同机台时间重叠';
        } elseif ($a['mold_id'] !== null && $a['mold_id'] === $b['mold_id'] && (int) $b['mold_id'] > 0) {
            $resource = 'mold:'.$a['mold_id'];
            $reason = '同模具并发占用（模具不可同时用于两台机台）';
        } else {
            return null;
        }

        return [
            'code' => 'resource_conflict',
            'message' => $reason,
            'resource' => $resource,
            'task_id_a' => $a['task_id'],
            'task_no_a' => $a['task_no'],
            'seq_a' => (int) ($a['seq'] ?? 0),
            'op_name_a' => $a['op_name'] ?? $a['op_type'] ?? '未知工序',
            // 返回实际/预测占用区间，避免用户看到“计划时间不重叠”却仍被拦截。
            'start_a' => $aStart->format('Y-m-d H:i:s'),
            'end_a' => $aEnd->format('Y-m-d H:i:s'),
            'task_id_b' => $b['task_id'],
            'task_no_b' => $b['task_no'],
            'seq_b' => (int) ($b['seq'] ?? 0),
            'op_name_b' => $b['op_name'] ?? $b['op_type'] ?? '未知工序',
            'start_b' => $bStart->format('Y-m-d H:i:s'),
            'end_b' => $bEnd->format('Y-m-d H:i:s'),
        ];
    }

    private function dedupeConflicts(array $conflicts): array
    {
        $seen = [];
        $result = [];
        foreach ($conflicts as $conflict) {
            $pair = $conflict['task_id_a'].':'.$conflict['task_id_b'].':'.$conflict['resource'];
            $reverse = $conflict['task_id_b'].':'.$conflict['task_id_a'].':'.$conflict['resource'];
            if (isset($seen[$pair]) || isset($seen[$reverse])) {
                continue;
            }
            $seen[$pair] = true;
            $result[] = $conflict;
        }

        return $result;
    }

    /**
     * 计算资源冲突使用的有效占用区间。
     *
     * - 实际已完工/任务已完工：不再占用资源；
     * - 已开工未完工：优先使用实际开始，结束取预测结束（不早于计划结束）；
     * - 尚未开工：使用计划区间。
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function effectiveOccupancyInterval(array $row): ?array
    {
        if (($row['status'] ?? null) === TaskSchedule::STATUS_DONE
            || ($row['task_status'] ?? null) === ProdTask::STATUS_DONE) {
            return null;
        }

        $start = ! empty($row['actual_start'])
            ? Carbon::parse($row['actual_start'])
            : Carbon::parse($row['plan_start']);
        $end = ! empty($row['actual_end'])
            ? Carbon::parse($row['actual_end'])
            : Carbon::parse($row['plan_end']);

        if (empty($row['actual_end']) && ! empty($row['forecast_end'])) {
            $forecastEnd = Carbon::parse($row['forecast_end']);
            if ($forecastEnd->gt($end)) {
                $end = $forecastEnd;
            }
        }

        return $end->gt($start) ? [$start, $end] : null;
    }

    /**
     * 级联顺延 sweep：按资源（机台/模具）消除不同任务间的重叠。
     * 重叠行整体平移所在任务的全部工序链（保持链内相对顺序）。
     *
     * 锚定任务（anchor，如插单任务/被打断任务）：优先级最高，绝不右移；
     * 与之冲突的其他任务右移让位（插单语义：紧急任务固定，受影响者顺延）。
     *
     * @param  list<array>  $rows  工作副本（引用修改）
     * @param  list<int>  $anchorTaskIds  锚定任务 id 集合
     * @param  Carbon|null  $preserveBefore  此时间点之前的排程不可被资源避让移动
     * @param  bool  $preserveStarted  产能重算场景下保留已开工任务，把未开工任务避让到其后
     */
    public function sweep(array &$rows, array $anchorTaskIds = [], ?Carbon $preserveBefore = null, bool $preserveStarted = false): void
    {
        $anchors = array_fill_keys(array_map('intval', $anchorTaskIds), true);

        // 收集全部资源键
        $resourceKeys = [];
        foreach ($rows as $row) {
            if ($row['machine_id'] !== null) {
                $resourceKeys['machine:'.$row['machine_id']] = true;
            }
            if (! empty($row['mold_id'])) {
                $resourceKeys['mold:'.$row['mold_id']] = true;
            }
        }

        for ($round = 0; $round < 40; $round++) {
            $changed = false;

            foreach (array_keys($resourceKeys) as $resourceKey) {
                [$type, $id] = explode(':', $resourceKey, 2);
                // 30 分钟是同一机台换任务的切换间隔；模具只要求不能并发，
                // 跨机台连续使用同一模具不应被额外制造空档。
                $requiredGapMinutes = $type === 'machine' ? self::TASK_SWITCH_GAP_MIN : 0;
                $items = array_values(array_filter($rows, fn ($row) => $this->rowOnResource($row, $type, (int) $id)));
                usort($items, fn ($a, $b) => Carbon::parse($a['plan_start'])->getTimestamp() <=> Carbon::parse($b['plan_start'])->getTimestamp());

                $prev = null;
                foreach ($items as $item) {
                    // 打断留档行（is_interrupted）：已完成部分的历史档案，不参与顺延与冲突
                    // （插单任务重叠其时段是打断语义的本意）
                    if ((int) ($item['is_interrupted'] ?? 0) === 1) {
                        continue;
                    }
                    $itemInterval = $this->effectiveOccupancyInterval($item);
                    if ($itemInterval === null) {
                        continue;
                    }
                    if ($prev === null) {
                        $prev = $item;
                        continue;
                    }
                    $prevInterval = $this->effectiveOccupancyInterval($prev);
                    if ($prevInterval === null) {
                        $prev = $item;
                        continue;
                    }
                    [$prevStart, $prevEnd] = $prevInterval;
                    [$itemStart, $itemEnd] = $itemInterval;
                    if ($preserveBefore !== null && $itemStart->lt($preserveBefore)) {
                        // 产能维护只影响当天及之后，历史行即使存在旧冲突也不能被此次操作改动。
                        $prev = $item;
                        continue;
                    }
                    $requiredStart = $prevEnd;
                    if ($item['task_id'] === $prev['task_id']
                        || $itemStart->gte($requiredStart->copy()->addMinutes($requiredGapMinutes))) {
                        $prev = $item;
                        continue;
                    }

                    $itemIsAnchor = isset($anchors[(int) $item['task_id']]);
                    $prevIsAnchor = isset($anchors[(int) $prev['task_id']]);

                    if ($itemIsAnchor && $prevIsAnchor) {
                        throw new ScheduleException('锚定任务之间存在资源冲突，无法自动顺延', [
                            ['code' => 'anchor_conflict', 'message' => '紧急任务之间时间重叠无法自动消解，请调整插入位置', 'resource' => $resourceKey],
                        ]);
                    }

                    $delta = $requiredStart->copy()->addMinutes($requiredGapMinutes)->getTimestamp()
                        - $itemStart->getTimestamp();

                    if ($preserveStarted) {
                        // 判断当前冲突的工序行，而不是整个任务：同一任务可能前序已开工、
                        // 但后续工序尚未开始，后续工序仍允许按新产能调整。
                        $itemHasStarted = $this->rowHasActualProgress($item);
                        $prevHasStarted = $this->rowHasActualProgress($prev);

                        if ($itemHasStarted && $prevHasStarted) {
                            // 两边都已有实际进度时不能再改写任何一边；保留现状，
                            // 让重算继续处理其他未开工任务，不把存量事实当成异常失败。
                            $prev = $item;
                            continue;
                        }

                        if ($itemHasStarted) {
                            // 后行已开工：只把前面的未开工任务移到已开工任务之后。
                            if ($preserveBefore !== null && $prevStart->lt($preserveBefore)) {
                                $prev = $item;
                                continue;
                            }
                            $moved = $this->moveUnstartedChain(
                                $rows,
                                (int) $prev['task_id'],
                                max(1, $itemEnd->copy()->addMinutes($requiredGapMinutes)->getTimestamp() - $prevStart->getTimestamp()),
                                $preserveBefore,
                            );
                        } else {
                            // 后行未开工：按正常资源避让顺延后行，已开工前置任务只作为锚点。
                            $moved = $this->moveUnstartedChain($rows, (int) $item['task_id'], max(60, $delta), $preserveBefore);
                        }

                        if (! $moved) {
                            $prev = $item;
                            continue;
                        }
                        $changed = true;
                        break 2;
                    }

                    if (! $itemIsAnchor) {
                        if ($this->taskHasStartedRows($rows, (int) $item['task_id'])) {
                            $this->throwActiveConflict($item, $resourceKey);
                        }
                        // 后行非锚定：顺延后行
                        $this->moveChain($rows, (int) $item['task_id'], max(60, $delta), $preserveBefore);
                    } else {
                        if ($this->taskHasStartedRows($rows, (int) $prev['task_id'])) {
                            $this->throwActiveConflict($prev, $resourceKey);
                        }
                        // 后行是锚定（如插单任务）：让先行者顺延到锚定任务之后
                        $this->moveChain($rows, (int) $prev['task_id'], max(1, $itemEnd->copy()->addMinutes($requiredGapMinutes)->getTimestamp() - $prevStart->getTimestamp()), $preserveBefore);
                    }
                    $changed = true;
                    break 2; // 每次只处理一个冲突，重新扫描（行已变化）
                }
            }

            if (! $changed) {
                break;
            }
        }
    }

    private function rowOnResource(array $row, string $type, int $id): bool
    {
        return match ($type) {
            'machine' => (int) $row['machine_id'] === $id,
            'mold' => ! empty($row['mold_id']) && (int) $row['mold_id'] === $id,
            default => false,
        };
    }

    /**
     * 平移某任务的全部工序链（秒），平移后按班制重算链内各工序起止
     * （保留 is_interrupted 留档行不动，其余行按 seq 链式重算，保证班制语义）
     */
    public function moveChain(array &$rows, int $taskId, int $deltaSeconds, ?Carbon $preserveBefore = null): void
    {
        foreach ($rows as $row) {
            if ((int) $row['task_id'] === $taskId
                && (int) ($row['is_interrupted'] ?? 0) === 0
                && $this->rowHasActualProgress($row)) {
                $this->throwActiveConflict($row, 'task:'.$taskId);
            }
        }

        foreach ($rows as &$row) {
            if ((int) $row['task_id'] === $taskId
                && (int) ($row['is_interrupted'] ?? 0) === 0
                && ($row['status'] ?? null) !== TaskSchedule::STATUS_DONE
                && empty($row['actual_end'])
                && ($preserveBefore === null || ! Carbon::parse($row['plan_start'])->lt($preserveBefore))) {
                $row['plan_start'] = Carbon::parse($row['plan_start'])->addSeconds($deltaSeconds);
                $row['plan_end'] = Carbon::parse($row['plan_end'])->addSeconds($deltaSeconds);
            }
        }
        unset($row);

        $this->recomputeTaskChain($rows, $taskId, $preserveBefore);
    }

    /**
     * 产能重算时只移动尚未发生实际生产的工序，不能因为同一任务前序已开工而整体失败。
     *
     * @return bool 是否实际移动了至少一道工序
     */
    private function moveUnstartedChain(array &$rows, int $taskId, int $deltaSeconds, ?Carbon $preserveBefore = null): bool
    {
        $moved = false;
        foreach ($rows as &$row) {
            if ((int) $row['task_id'] !== $taskId
                || (int) ($row['is_interrupted'] ?? 0) === 1
                || ($row['status'] ?? null) === TaskSchedule::STATUS_DONE
                || $this->rowHasActualProgress($row)
                || ($preserveBefore !== null && Carbon::parse($row['plan_start'])->lt($preserveBefore))) {
                continue;
            }
            $row['plan_start'] = Carbon::parse($row['plan_start'])->addSeconds($deltaSeconds);
            $row['plan_end'] = Carbon::parse($row['plan_end'])->addSeconds($deltaSeconds);
            $moved = true;
        }
        unset($row);

        if ($moved) {
            $this->recomputeTaskChain($rows, $taskId, $preserveBefore);
        }

        return $moved;
    }

    /**
     * 已发生实际生产的任务不能被自动顺延；否则计划时间会覆盖现场已经发生的事实。
     */
    private function taskHasStartedRows(array $rows, int $taskId): bool
    {
        foreach ($rows as $row) {
            if ((int) ($row['task_id'] ?? 0) !== $taskId
                || (int) ($row['is_interrupted'] ?? 0) === 1) {
                continue;
            }
            if ($this->rowHasActualProgress($row)) {
                return true;
            }
        }

        return false;
    }

    private function rowHasActualProgress(array $row): bool
    {
        return in_array($row['status'] ?? null, [TaskSchedule::STATUS_RUNNING, TaskSchedule::STATUS_DONE], true)
            || ! empty($row['actual_start'])
            || ! empty($row['actual_end'])
            || ! empty($row['has_report']);
    }

    private function throwActiveConflict(array $row, string $resourceKey): never
    {
        throw new ScheduleException('存在已开工工序冲突，无法自动顺延', [[
            'code' => 'active_schedule_conflict',
            'message' => "任务 {$row['task_no']} 已开工或已完成，不能自动移动其计划时间",
            'task_id' => (int) $row['task_id'],
            'task_no' => $row['task_no'],
            'resource' => $resourceKey,
        ]]);
    }

    /**
     * 按班制重算某任务的全部工序链：第一工序锚定当前 start，后续链式
     * （is_interrupted 留档行保持原状；产能未知的行保持平移值；传入生效日时，生效日前计划保持原状）
     */
    public function recomputeTaskChain(array &$rows, int $taskId, ?Carbon $preserveBefore = null): void
    {
        // 收集该任务行索引，按 seq 排序（留档行最后处理，保持相对顺序）
        $indexes = [];
        foreach ($rows as $idx => $row) {
            if ((int) $row['task_id'] === $taskId) {
                $indexes[] = $idx;
            }
        }
        usort($indexes, fn ($a, $b) => (int) $rows[$a]['seq'] <=> (int) $rows[$b]['seq']);

        $cursor = null;
        foreach ($indexes as $idx) {
            if ((int) ($rows[$idx]['is_interrupted'] ?? 0) === 1) {
                continue; // 留档行是历史档案，保持原状
            }

            if ($preserveBefore !== null && Carbon::parse($rows[$idx]['plan_start'])->lt($preserveBefore)) {
                // 产能修改当天以前的计划是历史快照，即使尚未报工也不因主数据编辑被改写。
                $cursor = Carbon::parse($rows[$idx]['plan_end']);
                continue;
            }

            if (($rows[$idx]['status'] ?? null) === TaskSchedule::STATUS_DONE
                || ! empty($rows[$idx]['actual_start'])
                || ! empty($rows[$idx]['actual_end'])
                || ! empty($rows[$idx]['has_report'])) {
                // 已开工（包括已完工）工序只作为后续工序的时间锚点，不能因产能维护
                // 回改已经发生的计划段；只有尚未开工的后续工序允许按新产能重算。
                $cursor = Carbon::parse($rows[$idx]['plan_end']);
                continue;
            }

            $shiftMode = $rows[$idx]['shift_mode'] ?? 'two_shift';
            $periods = $rows[$idx]['periods'] ?? [];

            if ($cursor === null) {
                $cursor = Carbon::parse($rows[$idx]['plan_start']);
            } else {
                $cursor = $this->effectiveStart($cursor, $shiftMode, $periods);
            }

            $capacity = (float) ($rows[$idx]['capacity'] ?? 0);
            if ($capacity <= 0) {
                continue; // 无法重算产能（数据缺失），保持平移后的值
            }

            $moldChange = (int) ($rows[$idx]['mold_change'] ?? 0);
            $qty = max(1, (int) $rows[$idx]['qty']);
            $rows[$idx]['plan_start'] = $cursor->copy();
            $rows[$idx]['plan_end'] = CapacityCalcService::estimateEndTime($cursor, $qty, $capacity, $moldChange, $shiftMode, $periods);
            $cursor = Carbon::parse($rows[$idx]['plan_end']);
        }
    }

    /**
     * 按资源列表过滤工作副本
     *
     * @param  list<array>  $rows
     * @param  list<string>  $resourceKeys
     * @return list<array>
     */
    public function rowsOnResources(array $rows, array $resourceKeys): array
    {
        return array_values(array_filter($rows, function ($row) use ($resourceKeys) {
            foreach ($resourceKeys as $key) {
                [$type, $id] = explode(':', $key, 2);
                if ($this->rowOnResource($row, $type, (int) $id)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * 工作副本 → 回写数组（落库字段）
     */
    public function toDbFields(array $row): array
    {
        return [
            'task_id' => (int) $row['task_id'],
            'seq' => (int) $row['seq'],
            'route_op_id' => (int) $row['route_op_id'],
            'op_type' => $row['op_type'],
            'op_name' => $row['op_name'] ?? $row['op_type'],
            'machine_id' => $row['machine_id'],
            'mold_id' => $row['mold_id'],
            'plan_start' => Carbon::parse($row['plan_start'])->format('Y-m-d H:i:s'),
            'plan_end' => Carbon::parse($row['plan_end'])->format('Y-m-d H:i:s'),
            'qty' => (int) $row['qty'],
            'status' => $row['status'],
            'source' => $row['source'],
            'is_interrupted' => (int) ($row['is_interrupted'] ?? 0),
            'done_qty' => (int) ($row['done_qty'] ?? 0),
            'is_overlap' => (int) ($row['is_overlap'] ?? 0),
            'note' => $row['note'] ?? null,
            'changeover_minutes' => (int) ($row['changeover_minutes'] ?? $row['mold_change'] ?? 0),
            // APS一期：预计与计划同源（首次保存 = 计划基线；重排后以新计划为预计基线，报工再滚动）
            'forecast_start' => Carbon::parse($row['plan_start'])->format('Y-m-d H:i:s'),
            'forecast_end' => Carbon::parse($row['plan_end'])->format('Y-m-d H:i:s'),
        ];
    }
}
