<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ProdTask;
use App\Models\IntegrationLog;
use App\Models\StockIn;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Order\Services\OrderService;
use App\Modules\Production\Services\TaskStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 校验并修复订单 → 工序排程 → 报工 → 入库闭环。
 *
 * 默认只读；--repair 只修复引用/冗余汇总，不改报工数量、不删除报工流水。
 */
class ValidateProductionClosure extends Command
{
    protected $signature = 'production:validate-closure {--repair : 修复失效排程引用、机台快照和派生汇总}';

    protected $description = '校验排产、报工、入库与订单工序/模具的闭环关联';

    public function handle(TaskStatusService $taskStatusService, OrderService $orderService): int
    {
        $errors = $this->scan($taskStatusService);
        if ($errors !== [] && ! $this->option('repair')) {
            $this->error('发现 '.count($errors).' 项闭环问题（只读校验，未修改数据）：');
            $this->table(['对象', '问题'], array_map(fn (array $error) => [$error['key'], $error['message']], $errors));
            $this->line('如确认修复当前演示/历史数据，请重新执行：php artisan production:validate-closure --repair');
            return self::FAILURE;
        }

        if ($this->option('repair')) {
            $repairSummary = DB::transaction(function () use ($taskStatusService, $orderService): array {
                $orphanReports = 0;
                $machineSnapshots = 0;
                $stockInFailureReasons = 0;

                $scheduleIds = TaskSchedule::query()->pluck('id')->all();
                WorkReport::query()->whereNotNull('schedule_id')->chunkById(100, function ($reports) use ($scheduleIds, &$orphanReports): void {
                    foreach ($reports as $report) {
                        if (in_array((int) $report->schedule_id, $scheduleIds, true)) {
                            continue;
                        }

                        // 排程已被合法重排/替换：报工金额与数量保持不变，降级为任务级历史流水。
                        $report->update(['schedule_id' => null, 'machine_id' => null]);
                        $orphanReports++;
                    }
                });

                WorkReport::query()->whereNotNull('schedule_id')->with('schedule:id,machine_id')->chunkById(100, function ($reports) use (&$machineSnapshots): void {
                    foreach ($reports as $report) {
                        $machineId = $report->schedule?->machine_id;
                        if ($machineId === null || (int) $report->machine_id === (int) $machineId) {
                            continue;
                        }

                        // 机台字段是报工时资源快照，跟随当前有效排程修正，不动数量和时间。
                        $report->update(['machine_id' => $machineId]);
                        $machineSnapshots++;
                    }
                });

                foreach (TaskSchedule::query()->with('task')->get() as $schedule) {
                    $reported = (int) WorkReport::where('schedule_id', $schedule->id)->sum('qty');
                    $positive = WorkReport::where('schedule_id', $schedule->id)
                        ->where('sign', 1)
                        ->orderByRaw('COALESCE(start_time, report_time)')
                        ->get(['start_time', 'end_time', 'report_time']);
                    $schedule->update([
                        'done_qty' => max(0, $reported),
                        'status' => $reported >= (int) $schedule->qty ? TaskSchedule::STATUS_DONE : ($reported > 0 ? TaskSchedule::STATUS_RUNNING : TaskSchedule::STATUS_PENDING),
                        'actual_start' => $positive->first()?->start_time ?? $positive->first()?->report_time,
                        'actual_end' => $reported >= (int) $schedule->qty
                            ? ($positive->sortByDesc(fn ($report) => $report->end_time ?? $report->report_time)->first()?->end_time
                                ?? $positive->last()?->report_time)
                            : null,
                    ]);
                }

                foreach (ProdTask::query()->with('reports')->get() as $task) {
                    $stocked = (int) DB::table('stock_ins')->where('task_id', $task->id)->sum('qty');
                    if ((int) $task->stocked_qty !== $stocked) {
                        $task->update(['stocked_qty' => $stocked]);
                    }
                    $taskStatusService->sync($task->fresh());
                }
                foreach (Order::query()->get() as $order) {
                    $orderService->syncStatus($order);
                }

                // 历史种子/联调数据可能只有 integration_logs 失败原因，回填到入库单，
                // 让仓库页面可以直接看到失败原因，不需要再跳到日志页查单。
                foreach (StockIn::query()->where('k3_push_status', StockIn::K3_FAILED)->get() as $stockIn) {
                    if (! empty($stockIn->k3_result)) {
                        continue;
                    }
                    $failure = IntegrationLog::query()
                        ->where('api_type', 'push_stock_in')
                        ->where('biz_type', 'stock_in')
                        ->where('biz_id', $stockIn->id)
                        ->whereIn('status', [IntegrationLog::STATUS_FAILED, IntegrationLog::STATUS_RETRY])
                        ->latest('id')
                        ->first();
                    if (! $failure) {
                        continue;
                    }
                    $stockIn->update([
                        'k3_result' => [
                            'error' => $failure->error_msg ?: data_get($failure->response_body, 'error', '金蝶推送失败，未返回具体原因'),
                            'retry_count' => (int) $failure->retry_count,
                            'failed_at' => $failure->finished_at?->toDateTimeString(),
                        ],
                    ]);
                    $stockInFailureReasons++;
                }

                return [
                    'orphan_reports' => $orphanReports,
                    'machine_snapshots' => $machineSnapshots,
                    'stock_in_failure_reasons' => $stockInFailureReasons,
                ];
            });

            $this->info('修复完成：失效排程报工 '.$repairSummary['orphan_reports'].' 条，机台快照 '.$repairSummary['machine_snapshots'].' 条，入库失败原因 '.$repairSummary['stock_in_failure_reasons'].' 条；数量流水未删除/改写。');
            $errors = $this->scan($taskStatusService);
        }

        if ($errors !== []) {
            $this->error('闭环校验仍有 '.count($errors).' 项问题：');
            $this->table(['对象', '问题'], array_map(fn (array $error) => [$error['key'], $error['message']], $errors));
            return self::FAILURE;
        }

        $this->info('闭环校验通过：订单、订单行、任务、工序排程、报工、入库、模具/机台关联一致。');
        return self::SUCCESS;
    }

    /** @return list<array{key: string, message: string}> */
    private function scan(TaskStatusService $taskStatusService): array
    {
        $errors = [];
        $tasks = ProdTask::query()->get()->keyBy('id');
        $schedules = TaskSchedule::query()->get()->keyBy('id');

        foreach (WorkReport::query()->get() as $report) {
            if (! $tasks->has($report->task_id)) {
                $errors[] = ['key' => "报工#{$report->id}", 'message' => '任务不存在'];
                continue;
            }
            if ($report->schedule_id !== null) {
                $schedule = $schedules->get($report->schedule_id);
                if (! $schedule) {
                    $errors[] = ['key' => "报工#{$report->id}", 'message' => '关联的工序排程已不存在'];
                } elseif ((int) $schedule->task_id !== (int) $report->task_id) {
                    $errors[] = ['key' => "报工#{$report->id}", 'message' => '工序排程不属于报工任务'];
                } elseif ($report->machine_id !== null && (int) $report->machine_id !== (int) $schedule->machine_id) {
                    $errors[] = ['key' => "报工#{$report->id}", 'message' => '报工机台与工序排程机台不一致'];
                }
            }
        }

        foreach ($tasks as $task) {
            $taskSchedules = $schedules->where('task_id', $task->id);
            $lastSeq = $taskSchedules->max('seq');
            $lastIds = $lastSeq === null
                ? collect()
                : $taskSchedules->where('seq', $lastSeq)->pluck('id');
            $reported = (int) WorkReport::where('task_id', $task->id)
                ->where(function ($query) use ($lastIds) {
                    $query->whereNull('schedule_id');
                    if ($lastIds->isNotEmpty()) {
                        $query->orWhereIn('schedule_id', $lastIds);
                    }
                })
                ->sum('qty');
            $stocked = (int) DB::table('stock_ins')->where('task_id', $task->id)->sum('qty');
            if ((int) $task->done_qty !== max(0, $reported)) {
                $errors[] = ['key' => "任务#{$task->id}", 'message' => "完工量 {$task->done_qty} 与末工序报工 {$reported} 不一致"];
            }
            if ((int) $task->stocked_qty !== $stocked) {
                $errors[] = ['key' => "任务#{$task->id}", 'message' => "已入库量 {$task->stocked_qty} 与入库流水 {$stocked} 不一致"];
            }
            if ($stocked > max(0, $reported)) {
                $errors[] = ['key' => "任务#{$task->id}", 'message' => '已入库量超过末工序完成量'];
            }

            $expected = $taskStatusService->calculate($task);
            if ($task->status !== $expected['status']) {
                $errors[] = ['key' => "任务#{$task->id}", 'message' => "状态 {$task->status} 与报工/实际开工推导状态 {$expected['status']} 不一致"];
            }
        }

        foreach (Order::query()->with('lines.tasks')->get() as $order) {
            foreach ($order->lines as $line) {
                $expectedDone = min(
                    (int) $line->qty,
                    max(0, (int) $line->tasks->sum(fn (ProdTask $task) => (int) $task->done_qty)),
                );
                if ((int) $line->done_qty !== $expectedDone) {
                    $errors[] = [
                        'key' => "订单行#{$line->id}",
                        'message' => "完成量 {$line->done_qty} 与任务末工序完成量 {$expectedDone} 不一致",
                    ];
                }
            }
        }

        foreach ($schedules as $a) {
            if ($a->machine_id === null || $a->is_interrupted) continue;
            foreach ($schedules as $b) {
                if ($a->id >= $b->id || $b->machine_id !== $a->machine_id || $a->task_id === $b->task_id || $b->is_interrupted) continue;
                if ($a->plan_start === null || $a->plan_end === null || $b->plan_start === null || $b->plan_end === null) continue;
                if ($a->plan_start < $b->plan_end && $b->plan_start < $a->plan_end) {
                    $errors[] = ['key' => "排程#{$a->id}/#{$b->id}", 'message' => '同一机台计划时间重叠'];
                }
            }
        }

        return $errors;
    }
}
