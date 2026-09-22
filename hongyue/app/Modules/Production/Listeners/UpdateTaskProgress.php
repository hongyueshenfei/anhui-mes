<?php

namespace App\Modules\Production\Listeners;

use App\Modules\Order\Services\OrderService;
use App\Modules\Production\Events\ReportSaved;
use App\Modules\Production\Services\TaskStatusService;

/**
 * 报工 → 任务进度/状态/偏差联动（C3）
 */
class UpdateTaskProgress
{
    public function __construct(
        private readonly TaskStatusService $taskStatusService,
        private readonly OrderService $orderService,
    ) {
    }

    public function handle(ReportSaved $event): void
    {
        $task = $event->task->fresh();

        $this->taskStatusService->sync($task);

        // 订单/明细状态聚合（报工后 生产中的订单状态应流转）
        $order = $task->line?->order;
        if ($order) {
            $this->orderService->syncStatus($order);
        }
    }
}