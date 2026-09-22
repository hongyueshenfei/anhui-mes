<?php

namespace App\Modules\Production\Events;

use App\Models\ProdTask;
use App\Models\WorkReport;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * 报工保存事件（C3 联动触发点）
 *
 * - UpdateTaskProgress：重算任务 done_qty/status/deviation_pct + 订单聚合
 * - UpdateMoldLife：合格数 ÷ 模穴数 → mold_life_logs + opened_count
 */
class ReportSaved
{
    use Dispatchable;

    public function __construct(
        public readonly WorkReport $report,
        public readonly ProdTask $task,
        public readonly int $netGoodQty,
    ) {
    }
}