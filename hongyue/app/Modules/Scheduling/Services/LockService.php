<?php

namespace App\Modules\Scheduling\Services;

use App\Models\AuditLog;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use Illuminate\Support\Facades\Auth;

/**
 * 排程锁定服务（B7：保存即锁定；解锁留痕）
 *
 * - 锁定：排产保存时隐式执行（ManualSchedule/Insert/AI accept 均锁定任务）
 * - 解锁：需提供原因，审计由路由中间件 audit.log 记录（params 含 reason）
 */
class LockService
{
    /**
     * 锁定任务
     */
    public function lock(array $taskIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $taskIds)));
        if ($ids === []) {
            return 0;
        }

        return ProdTask::whereIn('id', $ids)->update(['is_locked' => 1]);
    }

    /**
     * 解锁任务。
     *
     * 未来三天仍是默认冻结区；计划员如确需调整，可通过明确确认的异常解锁放行，
     * 但实际已开工/完工或已有报工流水的任务无论如何都不能解锁。
     */
    public function unlock(array $taskIds, ?string $reason = null, bool $exceptionUnlock = false): int
    {
        $ids = array_values(array_unique(array_map('intval', $taskIds)));
        if ($ids === []) {
            return 0;
        }

        // 进行中/已完成排程保护
        $running = TaskSchedule::whereIn('task_id', $ids)
            ->where(function ($query) {
                $query->whereIn('status', [TaskSchedule::STATUS_RUNNING, TaskSchedule::STATUS_DONE])
                    ->orWhereNotNull('actual_start')
                    ->orWhereNotNull('actual_end');
            })
            ->exists();

        if ($running) {
            throw new ScheduleException('存在进行中/已完成或已有实际进度的排程，不可解锁', [
                ['code' => 'running_schedule', 'message' => '存在进行中/已完成或已有实际进度的排程，不可解锁'],
            ]);
        }

        if (WorkReport::whereIn('task_id', $ids)->exists()) {
            throw new ScheduleException('存在报工流水的任务不可解锁', [
                ['code' => 'reported_task', 'message' => '任务已有实际报工流水，不可解锁后重排'],
            ]);
        }

        // 冻结窗口是“当前起未来 N 天”的可执行锁，不是保存即锁定的版本锁。
        // 任务主表的 in_progress 不参与判断，避免历史残留状态把尚未生产的任务锁死。
        $freezeUntil = now()->addDays((int) config('aps.freeze_days', 3));
        $frozen = TaskSchedule::query()
            ->whereIn('task_id', $ids)
            ->where('status', '!=', TaskSchedule::STATUS_DONE)
            ->where('plan_start', '>=', now())
            ->where('plan_start', '<=', $freezeUntil)
            ->first();
        if ($frozen && ! $exceptionUnlock) {
            throw new ScheduleException('任务处于当前起未来三天冻结区，不可直接解锁重排', [
                [
                    'code' => 'frozen_schedule',
                    'message' => '该任务计划开始时间在当前起未来三天内，属于冻结区；如需调整请先走异常解冻流程',
                    'task_id' => (int) $frozen->task_id,
                    'schedule_id' => (int) $frozen->id,
                    'freeze_until' => $freezeUntil->format('Y-m-d H:i:s'),
                ],
            ]);
        }

        if ($frozen && $exceptionUnlock && blank($reason)) {
            throw new ScheduleException('冻结区异常解锁必须填写原因', [
                ['code' => 'exception_unlock_reason_required', 'message' => '冻结区异常解锁必须填写原因'],
            ]);
        }

        $affected = ProdTask::whereIn('id', $ids)->update(['is_locked' => 0]);

        // 解锁留痕（B7）：独立审计记录，含原因与任务清单（路由中间件另记一条请求级日志）
        if ($affected > 0) {
            AuditLog::record(
                user: Auth::user(),
                module: 'scheduling',
                action: 'unlock_task',
                targetType: 'prod_task',
                targetId: $ids[0],
                detail: [
                    'task_ids' => $ids,
                    'reason' => $reason ?? '未填写原因',
                    'exception_unlock' => $exceptionUnlock && $frozen !== null,
                    'unlocked_count' => $affected,
                ],
                ip: request()->ip(),
            );
        }

        return $affected;
    }
}
