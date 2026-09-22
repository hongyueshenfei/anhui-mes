<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Machine;
use App\Models\Mold;
use App\Models\ProdTask;
use App\Models\RouteOp;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Services\AiServiceClient;
use App\Services\AiServiceException;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 自动排产编排服务（B6：一键自动排 → 异步求解 → 整版接受/局部采纳/回滚）
 *
 * 分工（D1/D2）：
 * - PHP：提交任务与机台参数（含每工序用时=产能+换模+班制），接收求解结果
 * - Python：约束组合优化（机台无重叠、模具互斥、最小化完工周期+加权拖期），异步 job
 * - 算法服务不可用：前端提示使用手工排产（铁保底）
 */
class AutoScheduleService
{
    public function __construct(
        private readonly AiServiceClient $client,
        private readonly ManualScheduleService $manualService,
        private readonly ScheduleEngineService $engine,
        private readonly LockService $lockService,
    ) {
    }

    /**
     * 提交自动排产任务
     *
     * @return array{job_id: string|null, task_count: int, machine_count: int, message: string, exceptions: list<array{task_id:int, task_no:string, code:string, reason:string}>, status?: string}
     *
     * @throws AiServiceException
     */
    public function submit(array $taskIds, int $userId): array
    {
        $now = now();
        $freezeUntil = $now->copy()->addDays((int) config('aps.freeze_days', 3));
        $tasks = ProdTask::with(['product', 'mold', 'line.order:id,order_no,status', 'schedules'])
            ->whereIn('id', $taskIds)
            ->whereIn('status', [ProdTask::STATUS_UNSCHEDULED, ProdTask::STATUS_SCHEDULED])
            ->get();

        if ($tasks->isEmpty()) {
            return [
                'job_id' => null,
                'task_count' => 0,
                'machine_count' => 0,
                'message' => '没有可自动排产的未开工任务',
                'exceptions' => [],
                'status' => 'no_valid_tasks',
            ];
        }

        $machines = Machine::where('is_active', 1)
            ->where('status', Machine::STATUS_RUNNING)
            ->get();

        // 自动排产必须使用最终生效模具：任务显式选择优先，产品默认模具兜底。
        // 不能只读 task->mold，否则历史导入/旧数据的空 mold_id 会让算法漏掉
        // 模具适配和跨机台互斥约束，直到接受结果时才暴露问题。
        // 单个任务的路线/模具异常不能阻断其它正常任务。异常任务不送入求解器，
        // 返回给前端单独展示，计划员可以按原因修正后再次提交。
        $exceptions = [];
        $validTasks = collect();
        $resolvedMoldIds = [];
        foreach ($tasks as $task) {
            $taskSchedules = $task->schedules
                ->where('is_interrupted', 0)
                ->values();
            $hasActualProgress = $taskSchedules->contains(fn (TaskSchedule $schedule) => in_array($schedule->status, [TaskSchedule::STATUS_RUNNING, TaskSchedule::STATUS_DONE], true)
                || $schedule->actual_start !== null
                || $schedule->actual_end !== null)
                || WorkReport::where('task_id', $task->id)->exists();

            // 自动重排只处理未开工的既有排程。生产事实不进入算法，避免“重排”覆盖现场进度。
            if ($hasActualProgress) {
                continue;
            }

            $hasFrozenSchedule = $taskSchedules->contains(fn (TaskSchedule $schedule) => $schedule->plan_start
                && $schedule->plan_start->gte($now)
                && $schedule->plan_start->lte($freezeUntil));
            if ($task->status === ProdTask::STATUS_UNSCHEDULED && $task->is_locked) {
                $exceptions[] = [
                    'task_id' => (int) $task->id,
                    'task_no' => (string) $task->task_no,
                    'code' => 'task_locked',
                    'reason' => "任务 {$task->task_no} 已锁定，未自动纳入排产",
                ];
                continue;
            }
            if ($task->status === ProdTask::STATUS_SCHEDULED && $hasFrozenSchedule && $task->is_locked) {
                $exceptions[] = [
                    'task_id' => (int) $task->id,
                    'task_no' => (string) $task->task_no,
                    'code' => 'frozen_schedule',
                    'reason' => "任务 {$task->task_no} 处于当前起未来三天冻结区，需先解锁后才能自动重排",
                ];
                continue;
            }

            if ($task->line?->order?->status === \App\Models\Order::STATUS_ROUTE_CONFIRM) {
                $exceptions[] = [
                    'task_id' => (int) $task->id,
                    'task_no' => (string) $task->task_no,
                    'code' => 'route_not_confirmed',
                    'reason' => "任务 {$task->task_no} 所属工单尚在「工艺确认」阶段，请先确认工艺再提交算法排产",
                ];
                continue;
            }

            try {
                $route = $this->engine->resolveRoute($task);
                $ops = $this->engine->effectiveRouteOps($task, $route);
            } catch (ScheduleException $e) {
                $exceptions[] = [
                    'task_id' => (int) $task->id,
                    'task_no' => (string) $task->task_no,
                    'code' => 'route_invalid',
                    'reason' => $e->getMessage(),
                ];
                continue;
            }

            $hasMolding = $ops->contains(fn (RouteOp $op) => in_array($op->op_type, ScheduleEngineService::MOLDING_OPS, true));
            if (! $hasMolding) {
                $resolvedMoldIds[(int) $task->id] = null;
                $validTasks->push($task);
                continue;
            }

            try {
                $moldId = $this->engine->resolveMold($task, null);
            } catch (ScheduleException $e) {
                $exceptions[] = [
                    'task_id' => (int) $task->id,
                    'task_no' => (string) $task->task_no,
                    'code' => 'mold_missing',
                    'reason' => $e->getMessage(),
                ];
                continue;
            }

            $mold = Mold::find($moldId);
            if (! $mold) {
                $exceptions[] = [
                    'task_id' => (int) $task->id,
                    'task_no' => (string) $task->task_no,
                    'code' => 'mold_not_found',
                    'reason' => "任务 {$task->task_no} 使用的模具 {$moldId} 不存在，无法提交算法排产",
                ];
                continue;
            }
            if (in_array($mold->status, [Mold::STATUS_REPAIR, Mold::STATUS_SCRAPPED], true)) {
                $exceptions[] = [
                    'task_id' => (int) $task->id,
                    'task_no' => (string) $task->task_no,
                    'code' => 'mold_unavailable',
                    'reason' => "任务 {$task->task_no} 使用的模具 {$mold->code} 当前为{$mold->status_label}，不可提交算法排产",
                ];
                continue;
            }

            $moldingOp = $ops->first(fn (RouteOp $op) => in_array($op->op_type, ScheduleEngineService::MOLDING_OPS, true));
            $targetResourceType = $moldingOp?->machine_type ?: $moldingOp?->resourceType();
            $targetWorkshopId = (int) ($moldingOp?->workshop_id ?? 0);
            $eligibleMachines = $machines
                ->when($targetResourceType, fn ($collection) => $collection->where('type', $targetResourceType))
                ->when($targetWorkshopId > 0, fn ($collection) => $collection->where('workshop_id', $targetWorkshopId))
                ->filter(fn (Machine $machine) => $task->product?->required_tonnage === null
                    || $machine->tonnage === null
                    || (int) $machine->tonnage >= (int) $task->product->required_tonnage);
            $compatibleMachineIds = $mold->machines()->pluck('machines.id');
            $eligibleMachines = $eligibleMachines->whereIn('id', $compatibleMachineIds);
            if ($eligibleMachines->isEmpty()) {
                $exceptions[] = [
                    'task_id' => (int) $task->id,
                    'task_no' => (string) $task->task_no,
                    'code' => 'no_eligible_machine',
                    'reason' => "任务 {$task->task_no} 没有可用的匹配机台，已按资源类型、车间、吨位和模具适配关系校验",
                ];
                continue;
            }

            $resolvedMoldIds[(int) $task->id] = $moldId;
            $validTasks->push($task);
        }

        if ($validTasks->isEmpty()) {
            return [
                'job_id' => null,
                'task_count' => 0,
                'machine_count' => $machines->count(),
                'message' => '没有可提交算法排产的正常任务，异常任务已列入清单',
                'exceptions' => $exceptions,
                'status' => 'no_valid_tasks',
            ];
        }

        $resolvedMolds = Mold::whereIn('id', array_values(array_filter($resolvedMoldIds)))
            ->get(['id', 'code', 'type'])
            ->keyBy('id');

        // 算法只负责为本次待排任务找位置，但不能忽略数据库中已经存在的锁定/已排任务。
        // 只传递仍可能占用资源的成型排程：本次候选任务的旧排程已从占用快照排除，
        // 已实际完工的任务以 actual_end 释放资源，
        // 未完工任务以 forecast_end（若被实际报工滚动）作为占用边界，且不早于计划结束；
        // 机台切换间隔由算法服务统一追加。
        $occupiedSchedules = TaskSchedule::query()
            ->whereNotIn('task_id', $validTasks->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->whereIn('machine_id', $machines->pluck('id')->map(fn ($id) => (int) $id)->all())
            ->whereIn('op_type', ScheduleEngineService::MOLDING_OPS)
            ->where('is_interrupted', 0)
            ->where(function ($query) use ($now) {
                $query
                    ->where('plan_end', '>', $now)
                    ->orWhere('forecast_end', '>', $now)
                    ->orWhere('actual_end', '>', $now)
                    // 现场已开工但计划已经过期时，actual_start 是唯一能证明
                    // 该机台仍被占用的事实；不能因为 forecast 尚未生成就漏算。
                    ->orWhere(function ($active) {
                        $active->whereNotNull('actual_start')->whereNull('actual_end');
                    });
            })
            ->get(['task_id', 'machine_id', 'mold_id', 'plan_start', 'plan_end', 'forecast_end', 'actual_start', 'actual_end', 'status']);

        $occupiedIntervals = [];
        foreach ($occupiedSchedules as $schedule) {
                // done 但没有 actual_end 属于历史脏数据，不能继续占用算法资源。
                if ($schedule->status === TaskSchedule::STATUS_DONE && ! $schedule->actual_end) {
                    continue;
                }

                $start = $schedule->actual_start ?: $schedule->plan_start;
                $end = $schedule->actual_end ?: $schedule->plan_end;
                if (! $schedule->actual_end && $schedule->forecast_end && $schedule->forecast_end->gt($end)) {
                    $end = $schedule->forecast_end;
                }

                // 已开工但没有实际结束，且计划/预测结束都已过去，说明系统无法
                // 得知这台机何时释放。宁可阻止算法排产并说明原因，也不能把新任务
                // 静默排到现场正在生产的机台上。
                if ($schedule->actual_start && ! $schedule->actual_end && (! $end || $end->lte($now))) {
                    throw new AiServiceException(
                        "任务 {$schedule->task_id} 的成型工序已开工但没有有效预测结束时间，暂不能自动排产，请先补报工或刷新预测",
                    );
                }
                if (! $end || $end->lte($now)) {
                    continue;
                }

                $occupiedIntervals[] = [
                    'task_id' => (int) $schedule->task_id,
                    'machine_id' => (int) $schedule->machine_id,
                    'mold_id' => $schedule->mold_id ? (int) $schedule->mold_id : null,
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $end->format('Y-m-d H:i:s'),
                ];
        }

        $payload = [
            'tasks' => $validTasks->map(function (ProdTask $task) use ($machines, $resolvedMoldIds, $resolvedMolds) {
                $ops = $this->taskOpsPayload($task);
                $molding = array_values(array_filter($ops, fn ($op) => in_array($op['op_type'], ScheduleEngineService::MOLDING_OPS, true)));
                $moldId = $resolvedMoldIds[(int) $task->id] ?? null;
                $mold = $moldId !== null ? $resolvedMolds->get($moldId) : null;
                $firstOp = $molding[0] ?? ($ops[0] ?? null);
                $targetResourceType = $firstOp['machine_type'] ?? ($firstOp['resource_type'] ?? null);
                $targetWorkshopId = isset($firstOp['workshop_id']) && $firstOp['workshop_id'] !== null
                    ? (int) $firstOp['workshop_id']
                    : null;
                $eligibleMachines = $machines->when(
                    $targetResourceType,
                    fn ($collection) => $collection->where('type', $targetResourceType),
                );
                if ($targetWorkshopId !== null && $targetWorkshopId > 0) {
                    $eligibleMachines = $eligibleMachines->where('workshop_id', $targetWorkshopId);
                }
                $requiredTonnage = $task->product?->required_tonnage;
                $eligibleMachines = $eligibleMachines->filter(
                    fn (Machine $machine) => $requiredTonnage === null
                        || $machine->tonnage === null
                        || (int) $machine->tonnage >= (int) $requiredTonnage,
                );
                if ($molding !== [] && $mold) {
                    $compatibleMachineIds = $mold->machines()->pluck('machines.id');
                    $eligibleMachines = $eligibleMachines->whereIn('id', $compatibleMachineIds);
                }

                return [
                    'id' => (int) $task->id,
                    'task_no' => $task->task_no,
                    'product_code' => $task->product->code ?? '',
                    'product_name' => $task->product->name ?? '',
                    'qty' => (int) $task->qty,
                    'priority' => (int) $task->priority,
                    'delivery_date' => $task->delivery_date?->format('Y-m-d'),
                    'mold_id' => $moldId,
                    'mold_code' => $mold?->code ?? '',
                    'order_no' => $task->line?->order?->order_no ?? '',
                    'molding_op' => $molding[0] ?? null, // 求解只排成型工序（人工线工序随后链式续排）
                    'ops' => $ops,
                    'eligible_machine_ids' => $eligibleMachines->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                ];
            })->values()->all(),
            'machines' => $machines->map(fn (Machine $m) => [
                'id' => (int) $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'type' => $m->type,
                'workshop_id' => $m->workshop_id,
                'workshop_name' => $m->workshop_name,
                'capacity_per_hour' => (float) $m->capacity_per_hour,
                'shift_mode' => $m->shift_mode,
            ])->values()->all(),
            'molds' => $resolvedMolds->values()->toArray(),
            'occupied_intervals' => $occupiedIntervals,
            'settings' => [
                'time_limit_sec' => 30,
                'weight_makespan' => 1.0,
                'weight_tardiness' => 5.0,
                // Laravel 是业务时间权威；算法服务不能自行读取操作系统时间，
                // 否则本地测试固定到 9 月 7 日时，算法会错误地从真实的 9 月 18 日起排。
                'start_at' => $now->format('Y-m-d H:i:s'),
            ],
        ];

        $jobId = $this->client->submitSchedule($payload);
        $exceptionSuffix = $exceptions === []
            ? ''
            : '；异常任务 '.count($exceptions).' 个未纳入求解';

        return [
            'job_id' => $jobId,
            'task_count' => $validTasks->count(),
            'machine_count' => $machines->count(),
            'message' => '已提交算法排产，正常任务 '.$validTasks->count().' 个'.$exceptionSuffix.'，预计 30 秒内完成求解',
            'exceptions' => $exceptions,
        ];
    }

    /**
     * 轮询求解结果
     *
     * @throws AiServiceException
     */
    public function poll(string $jobId): array
    {
        $result = $this->client->pollSchedule($jobId);

        return [
            'job_id' => $jobId,
            'status' => $result['status'] ?? 'unknown',
            'solution' => $result['solution'] ?? null,
            'meta' => $result['meta'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * 接受算法求解结果（整版接受 / 局部采纳）
     *
     * @param  list<array>  $assignments  [['task_id'=>, 'machine_id'=>, 'plan_start'=>, 'mold_id'=>?], ...]
     * @param  string  $mode  full(整版接受) / partial(局部采纳)
     *
     * @throws AiServiceException
     */
    public function accept(array $assignments, string $mode, ?string $jobId, int $userId): array
    {
        if ($assignments === []) {
            throw new AiServiceException('接受内容为空（无有效排程结果）');
        }

        $items = [];
        foreach ($assignments as $assignment) {
            $items[] = [
                'task_id' => (int) ($assignment['task_id'] ?? 0),
                'machine_id' => (int) ($assignment['machine_id'] ?? 0),
                'plan_start' => (string) ($assignment['plan_start'] ?? ''),
                'mold_id' => isset($assignment['mold_id']) ? (int) $assignment['mold_id'] : null,
            ];
        }

        // 求解器当前只负责成型机台/模具组合，喷漆、效果、包装等后续工序
        // 由 PHP 按工艺链补排。先在这里用同一套冲突内核做确定性后处理，
        // 避免多个任务在共享产线上同时开始，导致算法结果到保存阶段才失败。
        // 算法任务是异步计算的：接受前主数据可能已经变化，或用户打开了旧的建议结果。
        // 先用后端权威三匹配校正机台，避免把过期的非法机台建议直接送进保存流程。
        [$items, $machineAdjustments] = $this->normalizeAssignmentMachines($items);
        [$items, $adjustments] = $this->normalizeAssignments($items);
        $adjustments = array_merge($machineAdjustments, $adjustments);

        // 已排但未开工、且已超过冻结窗口的任务，沿用甘特图“窗口外可直接调整”的规则：
        // 接受算法建议前自动解锁，保存成功后 ManualScheduleService 会再次锁定。
        // 与保存放在同一事务中，避免接受失败后留下半解锁状态。
        $assignmentTaskIds = array_values(array_unique(array_map(
            fn (array $item): int => (int) $item['task_id'],
            $items,
        )));
        $result = DB::transaction(function () use ($items, $assignmentTaskIds, $userId, $mode, $jobId): array {
            $lockedReplanTaskIds = ProdTask::query()
                ->whereIn('id', $assignmentTaskIds)
                ->where('status', ProdTask::STATUS_SCHEDULED)
                ->where('is_locked', 1)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($lockedReplanTaskIds !== []) {
                $this->lockService->unlock(
                    $lockedReplanTaskIds,
                    '自动排产重排：已排但未开工且已超出冻结窗口',
                    false,
                );
            }

            // 业务冲突直接交给控制器按排产错误返回，不能包装成“算法服务不可用”，
            // 否则前端无法区分服务故障和任务/机台/时间冲突。
            return $this->manualService->save(
                $items,
                $userId,
                TaskSchedule::SOURCE_AI,
                false,
                '自动排产建议'.($mode === 'partial' ? '（局部采纳）' : '（整版接受）'),
                'algorithm:job:'.($jobId ?? 'unknown'),
            );
        });

        return $result + [
            'mode' => $mode,
            'job_id' => $jobId,
            'adjusted_assignments' => $adjustments,
        ];
    }

    /**
     * 对算法建议做后端确定性校正：完整展开工序链，并避让共享资源。
     *
     * 算法服务的输出只有“任务首工序机台 + 开始时间”，而工序链中的人工线
     * 资源由 PHP 权威配置决定。因此这里按建议起点、优先级、任务号稳定排序，
     * 将每个任务放入已存在排程和已校正任务之后；每次冲突按实际/预测有效占用
     * 区间右移，直到整条工序链无机台/产线/模具冲突。
     *
     * @param  list<array{task_id:int,machine_id:int,plan_start:string,mold_id:?int}> $items
     * @return array{0:list<array>,1:list<array>}
     * @throws ScheduleException
     */
    private function normalizeAssignments(array $items): array
    {
        $contexts = [];
        foreach ($items as $item) {
            $taskId = (int) ($item['task_id'] ?? 0);
            $task = ProdTask::with('product', 'line:id,order_id')->find($taskId);
            if (! $task) {
                throw new ScheduleException('任务不存在', [[
                    'code' => 'task_not_found',
                    'message' => '任务不存在',
                    'task_id' => $taskId,
                ]], 404);
            }

            $machineId = (int) ($item['machine_id'] ?? 0);
            $moldId = $this->engine->validateMatching(
                $task,
                $machineId,
                isset($item['mold_id']) && $item['mold_id'] !== null ? (int) $item['mold_id'] : null,
            );

            $contexts[] = [
                'task' => $task,
                'task_id' => $taskId,
                'machine_id' => $machineId,
                'mold_id' => $moldId > 0 ? $moldId : null,
                'start' => Carbon::parse((string) ($item['plan_start'] ?? '')),
                'original_start' => Carbon::parse((string) ($item['plan_start'] ?? '')),
            ];
        }

        usort($contexts, function (array $a, array $b): int {
            $startCompare = $a['start']->getTimestamp() <=> $b['start']->getTimestamp();
            if ($startCompare !== 0) {
                return $startCompare;
            }

            $priorityCompare = (int) $b['task']->priority <=> (int) $a['task']->priority;
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            return $a['task_id'] <=> $b['task_id'];
        });

        // 本次算法建议中的任务需要重新找位置，旧的未开工排程不能继续占用自己；
        // 否则“产能重算后出现空挡”的任务会被自己的旧时间段挡住，无法真正重排。
        $replannedTaskIds = array_values(array_unique(array_map(
            fn (array $context): int => (int) $context['task_id'],
            $contexts,
        )));
        $existingRows = array_values(array_filter(
            $this->engine->loadWorkRows(),
            fn (array $row): bool => ! in_array((int) ($row['task_id'] ?? 0), $replannedTaskIds, true),
        ));
        $placedRows = [];
        $normalized = [];
        $adjustments = [];

        foreach ($contexts as $context) {
            $start = $context['start']->copy();
            $chain = [];
            $resolved = false;

            for ($attempt = 0; $attempt < 100; $attempt++) {
                $chain = $this->engine->buildChain(
                    $context['task'],
                    $context['machine_id'],
                    (int) ($context['mold_id'] ?? 0),
                    $start,
                    TaskSchedule::SOURCE_AI,
                    array_merge($existingRows, $placedRows),
                );
                $conflicts = $this->engine->findConflicts(
                    $chain,
                    array_merge($existingRows, $placedRows),
                );

                if ($conflicts === []) {
                    $resolved = true;
                    break;
                }

                $delta = 0;
                foreach ($conflicts as $conflict) {
                    $isNewA = (int) ($conflict['task_id_a'] ?? 0) === $context['task_id'];
                    $isNewB = (int) ($conflict['task_id_b'] ?? 0) === $context['task_id'];
                    if (! $isNewA && ! $isNewB) {
                        continue;
                    }

                    $newStart = Carbon::parse($isNewA ? $conflict['start_a'] : $conflict['start_b']);
                    $otherEnd = Carbon::parse($isNewA ? $conflict['end_b'] : $conflict['end_a']);
                    $gapMinutes = str_starts_with((string) ($conflict['resource'] ?? ''), 'machine:')
                        ? ScheduleEngineService::TASK_SWITCH_GAP_MIN
                        : 0;
                    $requiredStart = $otherEnd->copy()->addMinutes($gapMinutes);
                    $delta = max($delta, $requiredStart->getTimestamp() - $newStart->getTimestamp());
                }

                if ($delta <= 0) {
                    throw new ScheduleException('算法结果存在无法校正的资源冲突', [[
                        'code' => 'algorithm_conflict_unresolved',
                        'message' => '算法建议与已有排程冲突，且无法计算顺延位置',
                        'task_id' => $context['task_id'],
                        'task_no' => $context['task']->task_no,
                        'conflicts' => $conflicts,
                    ]]);
                }

                $start->addSeconds($delta);
            }

            if (! $resolved) {
                throw new ScheduleException('算法结果顺延次数超过上限，未能形成无冲突排程', [[
                    'code' => 'algorithm_normalize_limit',
                    'message' => '算法建议经过多次资源避让仍无法形成有效排程',
                    'task_id' => $context['task_id'],
                    'task_no' => $context['task']->task_no,
                ]]);
            }

            $placedRows = array_merge($placedRows, $chain);
            $normalized[] = [
                'task_id' => $context['task_id'],
                'machine_id' => $context['machine_id'],
                'plan_start' => $start->format('Y-m-d H:i:s'),
                'mold_id' => $context['mold_id'],
            ];

            $downstreamGapInserted = collect($chain)->contains(fn (array $row): bool => (bool) ($row['gap_inserted'] ?? false));
            if (! $start->equalTo($context['original_start']) || $downstreamGapInserted) {
                $adjustments[] = [
                    'task_id' => $context['task_id'],
                    'task_no' => $context['task']->task_no,
                    'original_start' => $context['original_start']->format('Y-m-d H:i:s'),
                    'adjusted_start' => $start->format('Y-m-d H:i:s'),
                    'reason' => $start->equalTo($context['original_start']) && $downstreamGapInserted
                        ? '后续工序存在资源占用，已插入对应产线空档'
                        : '共享机台/产线或模具冲突，后端按约束顺延',
                ];
            }
        }

        return [$normalized, $adjustments];
    }

    /**
     * 接受算法结果前校正过期的机台建议。
     *
     * 算法结果生成到接受之间，模具适配关系可能被维护过；即使算法载荷当时合法，
     * 接受阶段也必须以后端当前的产品-模具-机台三匹配为准。若原机台已不合法，
     * 在同一批可用机台中寻找合法替代，后续仍由 normalizeAssignments 做时间避让。
     *
     * @return array{0:list<array>,1:list<array>}
     */
    private function normalizeAssignmentMachines(array $items): array
    {
        $adjustments = [];

        foreach ($items as $index => $item) {
            $taskId = (int) ($item['task_id'] ?? 0);
            $task = ProdTask::with('product', 'line:id,order_id')->find($taskId);
            if (! $task) {
                throw new ScheduleException('任务不存在', [[
                    'code' => 'task_not_found',
                    'message' => '任务不存在',
                    'task_id' => $taskId,
                ]], 404);
            }

            $requestedMachineId = (int) ($item['machine_id'] ?? 0);
            $requestedMachine = Machine::find($requestedMachineId);
            $moldId = array_key_exists('mold_id', $item) && $item['mold_id'] !== null
                ? (int) $item['mold_id']
                : null;

            try {
                $this->engine->validateMatching($task, $requestedMachineId, $moldId);
                continue;
            } catch (ScheduleException $requestedError) {
                // 旧建议或主数据变更导致原机台失配时，继续寻找当前合法资源。
            }

            $replacement = null;
            $replacementMoldId = null;
            $candidates = Machine::query()
                ->where('is_active', 1)
                ->where('status', Machine::STATUS_RUNNING)
                ->orderBy('id')
                ->get();
            foreach ($candidates as $candidate) {
                try {
                    $resolvedMoldId = $this->engine->validateMatching($task, (int) $candidate->id, $moldId);
                    $replacement = $candidate;
                    $replacementMoldId = $resolvedMoldId;
                    break;
                } catch (ScheduleException) {
                    continue;
                }
            }

            if (! $replacement) {
                throw new ScheduleException(
                    "任务 {$task->task_no} 的算法建议机台 ".($requestedMachine?->code ?? $requestedMachineId)." 不满足当前产品-模具-机台三匹配，且没有可替换的可用机台，请重新自动排产",
                    [[
                        'code' => 'algorithm_machine_mismatch',
                        'message' => '算法建议机台与当前模具适配关系不一致，且没有可替换的可用机台',
                        'task_id' => $task->id,
                        'task_no' => $task->task_no,
                        'machine_id' => $requestedMachineId,
                        'machine_code' => $requestedMachine?->code,
                    ]],
                );
            }

            $items[$index]['machine_id'] = (int) $replacement->id;
            if ($moldId === null && $replacementMoldId !== null) {
                $items[$index]['mold_id'] = (int) $replacementMoldId;
            }
            $adjustments[] = [
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'original_machine_id' => $requestedMachineId,
                'original_machine_code' => $requestedMachine?->code,
                'adjusted_machine_id' => (int) $replacement->id,
                'adjusted_machine_code' => $replacement->code,
                'reason' => '算法建议生成后资源匹配发生变化，已按当前产品-模具-机台约束替换为可用机台',
            ];
        }

        return [$items, $adjustments];
    }

    /**
     * 任务工序参数（PHP 权威产能口径 → Python 求解输入）
     */
    private function taskOpsPayload(ProdTask $task): array
    {
        try {
            $route = $this->engine->resolveRoute($task);
        } catch (\Throwable) {
            return [];
        }

        $opQtyBySeq = [];
        foreach ($task->effectiveOps() as $effectiveOp) {
            $seq = (int) ($effectiveOp['seq'] ?? 0);
            if ($seq > 0 && array_key_exists('qty', $effectiveOp) && $effectiveOp['qty'] !== null) {
                $opQtyBySeq[$seq] = max(1, (int) $effectiveOp['qty']);
            }
        }

        $ops = [];
        foreach ($this->engine->effectiveRouteOps($task, $route) as $op) {
            $isMolding = in_array($op->op_type, ScheduleEngineService::MOLDING_OPS, true);
            $opQty = $opQtyBySeq[(int) $op->seq] ?? (int) $task->qty;
            $machine = null;
            $candidateMachines = collect();
            if ($isMolding) {
                $machineType = $op->machine_type ?: $op->resourceType();
                $candidateMachines = Machine::where('type', $machineType)
                    ->where('is_active', 1)
                    ->where('status', Machine::STATUS_RUNNING)
                    ->when((int) ($op->workshop_id ?? 0) > 0, fn ($query) => $query->where('workshop_id', (int) $op->workshop_id))
                    ->get();
                $machine = $candidateMachines->first();
            }
            $capacity = $op->capacity_per_hour ?: ($machine?->capacity_per_hour ?: 0);
            $moldChange = (int) ($op->mold_change_min ?? $machine?->mold_change_min ?? 0);

            $duration = $capacity > 0
                ? \App\Modules\Plm\Services\CapacityCalcService::estimateSeconds($opQty, (float) $capacity, $moldChange)
                : 3600;

            // 成型工序的时长不能只按第一台候选机台计算；不同机台产能不同，
            // 算法必须带上机台级时长，避免选到高/低产能机台后 PHP 链式续排漂移。
            $durationByMachine = [];
            if ($isMolding) {
                foreach ($candidateMachines as $candidate) {
                    $candidateCapacity = $op->capacity_per_hour ?: ($candidate->capacity_per_hour ?: 0);
                    $candidateMoldChange = $op->mold_change_min === null
                        ? (int) ($candidate->mold_change_min ?? 0)
                        : (int) $op->mold_change_min;
                    if ($candidateCapacity > 0) {
                        $durationByMachine[(int) $candidate->id] =
                            \App\Modules\Plm\Services\CapacityCalcService::estimateSeconds(
                                $opQty,
                                (float) $candidateCapacity,
                                $candidateMoldChange,
                            );
                    }
                }
            }

            $opPayload = [
                'seq' => (int) $op->seq,
                'op_type' => $op->op_type,
                'op_name' => $op->op_name,
                'duration_seconds' => $duration,
                'machine_type' => $isMolding ? $machineType : null,
                'resource_type' => $op->resourceType(),
                'is_molding' => $isMolding,
                'workshop_id' => $op->workshop_id !== null ? (int) $op->workshop_id : null,
                'capacity_per_hour' => $capacity,
                'mold_change_min' => $moldChange,
            ];
            if ($durationByMachine !== []) {
                $opPayload['duration_seconds_by_machine'] = $durationByMachine;
            }
            $ops[] = $opPayload;
        }

        return $ops;
    }
}
