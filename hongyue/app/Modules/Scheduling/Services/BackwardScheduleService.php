<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Machine;
use App\Models\ProdTask;
use App\Modules\Plm\Services\CapacityCalcService;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use App\Modules\System\Services\ConfigService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * 倒排排程服务（APS一期）
 *
 * 从交期（deadline）逐工序向前倒推，得出首工序最晚可行开始时间：
 * - 结果早于最早允许开始（通常为 now）→ 返回 null，调用方降级为顺排
 * - 倒排只算「起点」；采纳后仍走正常顺排管线（三匹配/换模矩阵/冲突检测/版本快照），
 *   保持单一引擎出口——倒排是建议层能力，保存动作仍由计划员发起
 */
class BackwardScheduleService
{
    public function __construct(
        private readonly ScheduleEngineService $engine,
        private readonly ChangeoverService $changeoverService = new ChangeoverService(),
    ) {
    }

    /**
     * 计算首工序最晚可行开始时间
     *
     * @param  CarbonInterface  $deadline  交期上限（各工序结束不得晚于该时刻）
     * @param  CarbonInterface  $earliest  最早允许开始（通常为 now，不可排入过去）
     * @return CarbonInterface|null null = 交期倒推不可行（调用方降级顺排）
     *
     * @throws ScheduleException 无已发布工艺/产线缺失等（与顺排同口径）
     */
    public function latestStart(
        ProdTask $task,
        int $machineId,
        CarbonInterface $deadline,
        CarbonInterface $earliest,
        ?int $moldId = null,
    ): ?CarbonInterface
    {
        $route = $this->engine->resolveRoute($task);
        $placementMachine = Machine::findOrFail($machineId);
        $resolvedMoldId = $moldId;

        // 订单级工序数量口径（与 buildChain 完全一致）
        $opQtyBySeq = [];
        foreach ($task->effectiveOps() as $op) {
            $seq = (int) ($op['seq'] ?? 0);
            if ($seq > 0) {
                $opQtyBySeq[$seq] = max(1, (int) ($op['qty'] ?? $task->qty));
            }
        }

        $cursorEnd = Carbon::parse($deadline);
        $firstStart = null;

        // 逆序倒推：每道工序必须在其后道工序开始之前完成
        foreach ($this->engine->effectiveRouteOps($task, $route)->reverse() as $op) {
            $opQty = $opQtyBySeq[(int) $op->seq] ?? (int) $task->qty;
            $context = $this->engine->opResourceContext($task, $op, $placementMachine);
            $periods = ConfigService::shiftPeriods($context['shift_mode']);

            // 换模取兜底口径（矩阵需机台前序模具的时间线上下文，倒排计划时点尚不可知；
            // 采纳后走顺排管线会按矩阵精确重算并校验禁止切换）
            $isMolding = in_array($op->op_type, ScheduleEngineService::MOLDING_OPS, true);
            $moldChange = $isMolding ? $this->changeoverService->fallback($op, $context['machine']) : 0;

            $start = CapacityCalcService::estimateStartTime(
                $cursorEnd,
                $opQty,
                $context['capacity'],
                $moldChange,
                $context['shift_mode'],
                $periods,
            );

            if ($isMolding) {
                // 先用兜底值得到候选起点，再根据该起点前的实际/预测占用解析矩阵；
                // 矩阵值可能大于工序默认值，必须重新回退，否则倒排结果会在顺排
                // 重算时被推迟到交期之后。
                $resolvedMoldId ??= $this->engine->resolveMold($task, null);
                $fromMoldId = $this->changeoverService->previousMoldOnMachine(
                    (int) $context['machine']->id,
                    $start,
                    [],
                    (int) $task->id,
                );
                $exactMoldChange = $this->changeoverService->resolve(
                    $fromMoldId,
                    $resolvedMoldId,
                    $op,
                    $context['machine'],
                );
                if ($exactMoldChange !== $moldChange) {
                    $start = CapacityCalcService::estimateStartTime(
                        $cursorEnd,
                        $opQty,
                        $context['capacity'],
                        $exactMoldChange,
                        $context['shift_mode'],
                        $periods,
                    );
                }
            }

            $firstStart = $start;
            $cursorEnd = $start;
        }

        if ($firstStart === null || $firstStart->lt($earliest)) {
            return null;
        }

        return $firstStart;
    }
}
