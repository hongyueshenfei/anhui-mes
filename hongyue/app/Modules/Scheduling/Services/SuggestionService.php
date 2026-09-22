<?php

namespace App\Modules\Scheduling\Services;

use App\Models\ProdTask;
use App\Models\ScheduleSuggestion;
use App\Models\ScheduleVersion;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 顺延建议服务（APS一期：建议层）
 *
 * 报工滞后导致 forecast 破交期/偏差超阈值 → 系统生成顺延建议（pending）；
 * 计划员一键采纳 → 受影响任务未完工工序 plan 对齐 forecast（版本快照+留痕）；
 * 驳回 → dismissed 留痕。
 *
 * 铁律：手工排产是铁保底——建议只在报工后生成，采纳是计划员显式动作，
 * 系统绝不自动改排程；采纳后仍可手动调整。
 */
class SuggestionService
{
    public function __construct(
        private readonly ScheduleEngineService $engine,
        private readonly VersionService $versionService,
    ) {
    }

    /**
     * 报工驱动评估：是否生成/刷新顺延建议（每任务至多一条 pending）
     *
     * 触发源（任一命中）：
     * - 末工序 forecast_end 突破交期（delivery_date 当日末）
     * - 任务进度偏差超阈值（config('aps.deviation_threshold_pct')，对齐红灯「落后>10%」口径）
     */
    public function evaluateAfterReport(ProdTask $task, ?int $triggerReportId): void
    {
        $task = $task->fresh();
        if (! $task) {
            return;
        }

        $rows = TaskSchedule::where('task_id', $task->id)
            ->where('is_interrupted', 0)
            ->orderBy('seq')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $lastForecastEnd = null;
        foreach ($rows as $row) {
            $end = Carbon::parse($row->forecast_end ?? $row->plan_end);
            if ($lastForecastEnd === null || $end->gt($lastForecastEnd)) {
                $lastForecastEnd = $end;
            }
        }

        $reasons = [];

        $deadline = $task->delivery_date ? Carbon::parse((string) $task->delivery_date)->endOfDay() : null;
        if ($deadline !== null && $lastForecastEnd !== null && $lastForecastEnd->gt($deadline)) {
            $reasons[] = sprintf('预计完工 %s 突破交期 %s', $lastForecastEnd->format('Y-m-d H:i'), $task->delivery_date);
        }

        $threshold = (float) config('aps.deviation_threshold_pct', 10);
        if ((float) $task->deviation_pct > $threshold) {
            $reasons[] = sprintf('进度偏差 %s%% 超阈值 %s%%', $task->deviation_pct, $threshold);
        }

        if ($reasons === []) {
            return;
        }

        $affected = $rows->map(fn ($row) => [
            'schedule_id' => (int) $row->id,
            'seq' => (int) $row->seq,
            'op_name' => $row->op_name,
            'plan_end' => Carbon::parse($row->plan_end)->format('Y-m-d H:i:s'),
            'forecast_end' => $row->forecast_end ? Carbon::parse($row->forecast_end)->format('Y-m-d H:i:s') : null,
        ])->all();

        $order = $task->line?->order;
        $delayedOrders = [[
            'order_id' => $order?->id,
            'order_no' => $order?->order_no,
            'task_no' => $task->task_no,
            'product_code' => $task->product?->code,
            'product_name' => $task->product?->name,
            'delivery_date' => $task->delivery_date ? (string) $task->delivery_date : null,
            'forecast_end' => $lastForecastEnd?->format('Y-m-d H:i:s'),
        ]];

        ScheduleSuggestion::updateOrCreate(
            [
                'task_id' => $task->id,
                'status' => ScheduleSuggestion::STATUS_PENDING,
                'type' => ScheduleSuggestion::TYPE_POSTPONE,
            ],
            [
                'trigger_report_id' => $triggerReportId,
                'affected' => $affected,
                'delayed_orders' => $delayedOrders,
                'reason' => implode('；', $reasons),
            ],
        );
    }

    /**
     * 采纳建议：受影响任务未完工工序 plan 对齐 forecast
     *
     * 事务内：对齐排程 → 任务级 plan 首尾刷新 → 版本快照（可回滚）→ 建议置 accepted
     *
     * @return array{task_id: int, version_id: int, aligned: int}
     *
     * @throws ScheduleException 建议已处理/任务不存在
     */
    public function accept(ScheduleSuggestion $suggestion, int $userId): array
    {
        if ($suggestion->status !== ScheduleSuggestion::STATUS_PENDING) {
            throw new ScheduleException('建议已处理，不能重复采纳', [
                ['code' => 'suggestion_processed', 'message' => '建议已处理，不能重复采纳', 'suggestion_id' => $suggestion->id],
            ], 422);
        }

        $task = ProdTask::find($suggestion->task_id);
        if (! $task) {
            throw new ScheduleException('建议对应任务不存在', [
                ['code' => 'task_not_found', 'message' => '建议对应任务不存在', 'suggestion_id' => $suggestion->id],
            ], 404);
        }

        return DB::transaction(function () use ($suggestion, $task, $userId) {
            $beforeRows = $this->engine->loadWorkRows();

            $rows = TaskSchedule::where('task_id', $task->id)
                ->where('is_interrupted', 0)
                ->where('status', '!=', TaskSchedule::STATUS_DONE)
                ->get();

            if ($rows->isEmpty()) {
                throw new ScheduleException('建议对应任务没有可调整的未完工工序', [
                    ['code' => 'suggestion_schedule_missing', 'message' => '建议对应任务没有可调整的未完工工序', 'task_id' => $task->id],
                ]);
            }

            // 当前工序一旦开工，计划字段只作为基线留痕，不能被“采纳预计”覆盖；
            // 顺延建议只调整尚未开工的后续工序。
            $adjustableRows = $rows->filter(fn (TaskSchedule $row) => ! $this->rowHasActualProgress($row))->values();
            if ($adjustableRows->isEmpty()) {
                throw new ScheduleException('建议对应任务没有尚未开工的工序可调整', [
                    ['code' => 'suggestion_no_editable_rows', 'message' => '当前工序已开工，暂无尚未开工的后续工序可调整', 'task_id' => $task->id],
                ]);
            }

            // 先在工作副本中模拟“计划对齐预计”，再对所有外部资源做统一冲突校验。
            // 不能先 update 再补校验，否则失败时容易留下半套已改计划。
            $changedRows = [];
            $aligned = 0;
            foreach ($adjustableRows as $row) {
                $patch = [];
                if ($row->forecast_start !== null) {
                    $patch['plan_start'] = $row->forecast_start;
                }
                if ($row->forecast_end !== null) {
                    $patch['plan_end'] = $row->forecast_end;
                }
                if ($patch !== []) {
                    $candidate = collect($beforeRows)->first(fn ($item) => (int) $item['id'] === (int) $row->id);
                    if ($candidate !== null) {
                        $candidate['plan_start'] = $patch['plan_start'] ?? $candidate['plan_start'];
                        $candidate['plan_end'] = $patch['plan_end'] ?? $candidate['plan_end'];
                        $changedRows[] = $candidate;
                    }
                    $aligned++;
                }
            }

            if ($changedRows !== []) {
                $otherRows = array_values(array_filter(
                    $beforeRows,
                    fn ($item) => (int) $item['task_id'] !== (int) $task->id
                ));
                $conflicts = $this->engine->findConflicts($changedRows, $otherRows);
                if ($conflicts !== []) {
                    throw new ScheduleException('顺延建议与现有排程冲突，未采纳', $conflicts);
                }

                // 不能只比较被改动的行；当前工序被保护时，仍需校验后续工序没有被调到它之前。
                $patchById = collect($changedRows)->keyBy('id');
                $bySeq = collect($beforeRows)
                    ->filter(fn ($item) => (int) $item['task_id'] === (int) $task->id && (int) ($item['is_interrupted'] ?? 0) === 0)
                    ->map(function ($item) use ($patchById) {
                        $patch = $patchById->get($item['id']);
                        if ($patch !== null) {
                            $item['plan_start'] = $patch['plan_start'];
                            $item['plan_end'] = $patch['plan_end'];
                        }

                        return $item;
                    })
                    ->sortBy('seq')
                    ->values()
                    ->all();
                usort($bySeq, fn ($a, $b) => (int) $a['seq'] <=> (int) $b['seq']);
                for ($i = 1; $i < count($bySeq); $i++) {
                    if (Carbon::parse($bySeq[$i]['plan_start'])->lt(Carbon::parse($bySeq[$i - 1]['plan_end']))) {
                        throw new ScheduleException('顺延建议破坏工序先后顺序，未采纳', [
                            [
                                'code' => 'process_order_conflict',
                                'message' => '顺延后的工序开始时间早于前序工序结束时间',
                                'task_id' => $task->id,
                                'seq' => $bySeq[$i]['seq'],
                            ],
                        ]);
                    }
                }

                foreach ($adjustableRows as $row) {
                    $candidate = collect($changedRows)->firstWhere('id', $row->id);
                    if ($candidate === null) {
                        continue;
                    }
                    $row->update([
                        'plan_start' => Carbon::parse($candidate['plan_start']),
                        'plan_end' => Carbon::parse($candidate['plan_end']),
                    ]);
                }
            }

            // 任务级计划首尾刷新（与手工保存同口径）
            $fresh = TaskSchedule::where('task_id', $task->id)->where('is_interrupted', 0)->get();
            $task->update([
                'plan_start' => $fresh->min('plan_start'),
                'plan_end' => $fresh->max('plan_end'),
            ]);

            // 版本快照（整版回滚支撑，与手工保存同链路）
            $afterRows = $this->engine->loadWorkRows();
            $version = $this->versionService->snapshot(
                $userId,
                ScheduleVersion::TYPE_MANUAL,
                $beforeRows,
                $afterRows,
                [],
                'aps-suggestion:'.$suggestion->id,
            );

            $suggestion->update([
                'status' => ScheduleSuggestion::STATUS_ACCEPTED,
                'processed_by' => $userId,
                'processed_at' => Carbon::now(),
            ]);

            return [
                'task_id' => (int) $task->id,
                'version_id' => (int) $version->id,
                'aligned' => $aligned,
            ];
        });
    }

    private function rowHasActualProgress(TaskSchedule $row): bool
    {
        return in_array($row->status, [TaskSchedule::STATUS_RUNNING, TaskSchedule::STATUS_DONE], true)
            || $row->actual_start !== null
            || $row->actual_end !== null
            || WorkReport::where('schedule_id', $row->id)->exists();
    }

    /**
     * 驳回建议（留痕，不改排程）
     *
     * @throws ScheduleException 建议已处理
     */
    public function dismiss(ScheduleSuggestion $suggestion, int $userId): void
    {
        if ($suggestion->status !== ScheduleSuggestion::STATUS_PENDING) {
            throw new ScheduleException('建议已处理', [
                ['code' => 'suggestion_processed', 'message' => '建议已处理', 'suggestion_id' => $suggestion->id],
            ], 422);
        }

        $suggestion->update([
            'status' => ScheduleSuggestion::STATUS_DISMISSED,
            'processed_by' => $userId,
            'processed_at' => Carbon::now(),
        ]);
    }
}
