<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ProdTask;
use App\Models\User;
use App\Models\WorkReport;
use App\Modules\Production\Services\ReportService;
use App\Modules\Warehouse\Services\StockInService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 数据修复：已入库（produced）订单补全成品报工与入库单
 *
 * 2026-08-31 业务确认「入库只记载最后工序成品，中间状态不入库」：
 * - 旧种子报工按工序分摊（最后工序只报一部分），且 produced 订单无入库单
 * - 本命令只补足缺口，不删除/修改既有报工流水（报工台账只增不改）
 * - 仅处理 status=produced 的订单，不触碰其他订单（含用户手工数据）
 */
class FixProducedOrderStock extends Command
{
    protected $signature = 'orders:fix-produced-stock';

    protected $description = '修复已入库订单：重建全量成品报工并补全入库单';

    public function handle(ReportService $reportService, StockInService $stockService): int
    {
        $orders = Order::where('status', Order::STATUS_PRODUCED)->with('lines.tasks')->get();
        if ($orders->isEmpty()) {
            $this->info('没有需要修复的已入库订单');
            return self::SUCCESS;
        }

        $operator = User::where('employee_no', 'admin')->first() ?? User::first();
        if (! $operator) {
            $this->error('无可用操作人（请先迁移+种子）');
            return self::FAILURE;
        }

        $fixed = 0;
        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                foreach ($line->tasks as $task) {
                    $fixed += $this->rebuildTask($task, $reportService, $stockService, $operator);
                }
            }
        }

        $this->info("修复完成：{$fixed} 个任务已重建成品报工并补全入库单");
        return self::SUCCESS;
    }

    private function rebuildTask(ProdTask $task, ReportService $reportService, StockInService $stockService, User $operator): int
    {
        return DB::transaction(function () use ($task, $reportService, $stockService, $operator) {
            $schedules = $task->schedules()->orderBy('seq')->get();
            if ($schedules->isEmpty()) {
                return 0; // 无排程任务（理论上 produced 订单必有）
            }

            // 补足报工：每道工序达到任务量（成品口径取最后工序），已有流水不重复写入。
            foreach ($schedules as $schedule) {
                $reported = (int) $task->reports()->where('schedule_id', $schedule->id)->sum('qty');
                $missing = max(0, (int) $task->qty - $reported);
                if ($missing === 0) continue;
                $reportService->report([
                    'task_id' => $task->id,
                    'schedule_id' => $schedule->id,
                    'qty' => $missing,
                    'defect_qty' => 0,
                    'worker_id' => null,
                    'source' => WorkReport::SOURCE_LEADER_BATCH,
                    'report_time' => now()->subDays(1)->format('Y-m-d H:i:s'),
                ], $operator);
            }

            // 成品全量入库（最后工序报工 qty → 可入库 qty）
            $stockService->create($task->id, (int) $task->qty, $operator->id, '数据修复：成品全量入库');

            return 1;
        });
    }
}
