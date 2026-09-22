<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 存量排程 forecast 回填（APS一期）
 *
 * forecast 字段上线前已保存的排程为 NULL——回填为 plan 同值（预计=计划基线），
 * 后续报工滚动才会产生偏差（虚线段/顺延建议）。纯数据修复，可安全重跑。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_schedules')
            ->whereNull('forecast_start')
            ->update([
                'forecast_start' => DB::raw('plan_start'),
                'forecast_end' => DB::raw('plan_end'),
            ]);
    }

    public function down(): void
    {
        // 回填为派生值，不回退（down 仅结构迁移职责；如需清空可手动）
    }
};
