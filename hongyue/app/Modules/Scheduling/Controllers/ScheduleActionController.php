<?php

namespace App\Modules\Scheduling\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use App\Modules\Scheduling\Services\AutoScheduleService;
use App\Modules\Scheduling\Services\InsertScheduleService;
use App\Modules\Scheduling\Services\LockService;
use App\Modules\Scheduling\Services\ManualScheduleService;
use App\Modules\Scheduling\Services\ScheduleCapacityRecalculationService;
use App\Modules\Scheduling\Services\SuggestionService;
use App\Services\AiServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 排产操作控制器（架构 §4：手工保存/插单/算法排产/锁定）
 */
class ScheduleActionController extends Controller
{
    public function __construct(
        private readonly ManualScheduleService $manualService,
        private readonly InsertScheduleService $insertService,
        private readonly AutoScheduleService $autoService,
        private readonly LockService $lockService,
        private readonly ScheduleCapacityRecalculationService $capacityRecalculationService,
        private readonly SuggestionService $suggestionService,
    ) {
    }

    /**
     * 手工排程保存（B2/B3/B7/B8；APS一期支持 backward 倒排）
     *
     * POST /api/schedule/manual-save
     * body: {items: [{task_id, machine_id, plan_start, mold_id?, deadline?}], force?: bool, note?: string, mode?: forward|backward}
     */
    public function manualSave(Request $request): JsonResponse
    {
        $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.task_id' => ['required', 'integer'],
            'items.*.machine_id' => ['required', 'integer'],
            'items.*.plan_start' => ['required', 'date'],
            'items.*.mold_id' => ['nullable', 'integer'],
            'items.*.deadline' => ['nullable', 'date'],
            'force' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'in:forward,backward'],
        ]);

        try {
            $result = $this->manualService->save(
                $request->input('items'),
                (int) $request->user()?->id,
                'manual',
                $request->boolean('force'),
                $request->input('note'),
                null,
                $request->input('mode', ManualScheduleService::MODE_FORWARD),
            );
        } catch (ScheduleException $e) {
            return $this->scheduleError($e);
        }

        $message = $request->boolean('force') && $result['conflicts'] !== []
            ? '已强制排入（存在重叠，已标记）'
            : '排程保存成功（任务已锁定）';
        if ($result['backward_downgraded'] !== []) {
            $message .= '；'.implode('、', $result['backward_downgraded']).' 交期倒推不可行，已按顺排处理';
        }

        return $this->ok($result, $message);
    }

    /**
     * 单道工序调整（只修改当前排程行，其他工序保持原计划）。
     *
     * POST /api/schedule/operation-adjust
     * body: {schedule_id: int, machine_id: int, plan_start: date, plan_end?: date, edge?: start|end, note?: string}
     */
    public function operationAdjust(Request $request): JsonResponse
    {
        $request->validate([
            'schedule_id' => ['required', 'integer'],
            'machine_id' => ['required', 'integer'],
            'plan_start' => ['required', 'date'],
            'plan_end' => ['nullable', 'date'],
            'edge' => ['nullable', 'in:start,end'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->manualService->adjustOperation(
                (int) $request->input('schedule_id'),
                (int) $request->input('machine_id'),
                (string) $request->input('plan_start'),
                (int) $request->user()?->id,
                $request->input('note'),
                $request->input('plan_end'),
                $request->input('edge'),
            );
        } catch (ScheduleException $e) {
            return $this->scheduleError($e);
        }

        return $this->ok($result, '单道工序排程已调整，其他工序保持原计划');
    }

    /**
     * 按当前机台/产线最新产能重算已排但未开工的工序。
     *
     * POST /api/schedule/recalculate
     */
    public function recalculate(): JsonResponse
    {
        try {
            $result = $this->capacityRecalculationService->recalculateAll();
        } catch (ScheduleException $e) {
            return $this->scheduleError($e);
        }

        return $this->ok($result, "排程重算完成：涉及 {$result['task_count']} 个工单，调整 {$result['changed_count']} 条工序");
    }

    /**
     * 插单预览（不落库）
     *
     * POST /api/schedule/insert
     * body: {task_id, machine_id, plan_start, strategy?: wait|interrupt, mold_id?, force_interrupt?: bool}
     */
    public function insert(Request $request): JsonResponse
    {
        $validated = $this->validateInsert($request);

        try {
            $preview = $this->insertService->preview(
                (int) $validated['task_id'],
                (int) $validated['machine_id'],
                (string) $validated['plan_start'],
                (string) ($validated['strategy'] ?? 'wait'),
                isset($validated['mold_id']) ? (int) $validated['mold_id'] : null,
                (bool) ($validated['force_interrupt'] ?? false),
            );
        } catch (ScheduleException $e) {
            return $this->scheduleError($e);
        }

        return $this->ok($preview, '插单预览（未生效）');
    }

    /**
     * 插单确认（B4：一键确认生效）
     *
     * POST /api/schedule/insert/confirm
     */
    public function insertConfirm(Request $request): JsonResponse
    {
        $validated = $this->validateInsert($request);

        try {
            $result = $this->insertService->confirm(
                (int) $validated['task_id'],
                (int) $validated['machine_id'],
                (string) $validated['plan_start'],
                (int) $request->user()?->id,
                (string) ($validated['strategy'] ?? 'wait'),
                isset($validated['mold_id']) ? (int) $validated['mold_id'] : null,
                (bool) ($validated['force_interrupt'] ?? false),
            );
        } catch (ScheduleException $e) {
            return $this->scheduleError($e);
        }

        return $this->ok($result, '插单已确认生效（受影响任务已顺延，可回滚）');
    }

    /**
     * 插单回滚（恢复上一版本）
     *
     * POST /api/schedule/insert/rollback  body: {version_id}
     */
    public function insertRollback(Request $request): JsonResponse
    {
        $request->validate(['version_id' => ['required', 'integer']]);

        try {
            $version = $this->insertService->rollback((int) $request->input('version_id'), (int) $request->user()?->id);
            $this->suggestionService->refreshAllScheduledTasks();
        } catch (ScheduleException $e) {
            return $this->scheduleError($e);
        }

        return $this->ok($version, '插单已回滚，排程已恢复');
    }

    /**
     * 提交算法排产（B6）
     *
     * POST /api/schedule/auto  body: {task_ids?: [int]}
     */
    public function auto(Request $request): JsonResponse
    {
        $request->validate(['task_ids' => ['nullable', 'array', 'max:1000'], 'task_ids.*' => ['integer']]);

        try {
            // 未指定 → 全部未开工候选任务：包含待排任务，以及已排但尚未开工、
            // 且不在冻结窗口内的任务；AutoScheduleService 继续做事实/冻结校验。
            $taskIds = $request->input('task_ids');
            if ($taskIds === null || $taskIds === []) {
                $taskIds = \App\Models\ProdTask::whereIn('status', [
                    \App\Models\ProdTask::STATUS_UNSCHEDULED,
                    \App\Models\ProdTask::STATUS_SCHEDULED,
                ])->pluck('id')->all();
            }
            $result = $this->autoService->submit(array_map('intval', $taskIds), (int) $request->user()?->id);
        } catch (AiServiceException $e) {
            return $this->aiUnavailable($e);
        }

        return $this->ok($result, '算法排产已提交');
    }

    /**
     * 轮询求解结果
     *
     * GET /api/schedule/auto/{jobId}
     */
    public function autoPoll(Request $request, string $jobId): JsonResponse
    {
        try {
            $result = $this->autoService->poll($jobId);
        } catch (AiServiceException $e) {
            return $this->aiUnavailable($e);
        }

        return $this->ok($result);
    }

    /**
     * 接受求解结果（整版接受/局部采纳）
     *
     * POST /api/schedule/auto/accept
     * body: {job_id?, mode?: full|partial, assignments: [{task_id, machine_id, plan_start, mold_id?}]}
     */
    public function autoAccept(Request $request): JsonResponse
    {
        $request->validate([
            'job_id' => ['nullable', 'string', 'max:64'],
            'mode' => ['nullable', 'in:full,partial'],
            'assignments' => ['required', 'array', 'min:1', 'max:1000'],
            'assignments.*.task_id' => ['required', 'integer'],
            'assignments.*.machine_id' => ['required', 'integer'],
            'assignments.*.plan_start' => ['required', 'date'],
            'assignments.*.mold_id' => ['nullable', 'integer'],
        ]);

        try {
            $result = $this->autoService->accept(
                $request->input('assignments'),
                (string) $request->input('mode', 'full'),
                $request->input('job_id'),
                (int) $request->user()?->id,
            );
        } catch (AiServiceException | ScheduleException $e) {
            return $e instanceof AiServiceException ? $this->aiUnavailable($e) : $this->scheduleError($e);
        }

        return $this->ok($result, '算法排产结果已'.($request->input('mode', 'full') === 'partial' ? '局部采纳' : '整版接受'));
    }

    /**
     * 锁定任务（B7）
     *
     * POST /api/schedule/lock  body: {task_ids: [int]}
     */
    public function lock(Request $request): JsonResponse
    {
        $request->validate(['task_ids' => ['required', 'array', 'min:1'], 'task_ids.*' => ['integer']]);

        $count = $this->lockService->lock($request->input('task_ids'));

        return $this->ok(['locked_count' => $count], "已锁定 {$count} 个任务");
    }

    /**
     * 解锁任务（留痕）
     *
     * POST /api/schedule/unlock  body: {task_ids: [int], reason?: string, exception_unlock?: bool}
     */
    public function unlock(Request $request): JsonResponse
    {
        $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:255'],
            'exception_unlock' => ['nullable', 'boolean'],
        ]);

        try {
            $count = $this->lockService->unlock(
                $request->input('task_ids'),
                $request->input('reason'),
                $request->boolean('exception_unlock'),
            );
        } catch (ScheduleException $e) {
            return $this->scheduleError($e);
        }

        return $this->ok(['unlocked_count' => $count], "已解锁 {$count} 个任务");
    }

    /**
     * 插单请求公共校验
     */
    private function validateInsert(Request $request): array
    {
        return $request->validate([
            'task_id' => ['required', 'integer'],
            'machine_id' => ['required', 'integer'],
            'plan_start' => ['required', 'date'],
            'strategy' => ['nullable', 'in:wait,interrupt'],
            'mold_id' => ['nullable', 'integer'],
            'force_interrupt' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * 排产业务错误统一响应
     */
    private function scheduleError(ScheduleException $e): JsonResponse
    {
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409;

        return response()->json([
            'code' => $status,
            'message' => $e->getMessage(),
            'data' => ['errors' => $e->errors()],
        ], $status);
    }

    /**
     * AI 服务不可用降级响应（B6：回退手工排产）
     */
    private function aiUnavailable(AiServiceException $e): JsonResponse
    {
        return response()->json([
            'code' => 503,
            'message' => '算法排产服务暂不可用：'.$e->getMessage().'（已降级，请使用手工排产）',
            'data' => null,
        ], 503);
    }
}
