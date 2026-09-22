<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * task_schedules 冗余 op_name（2026-08-31 业务确认）
 *
 * 订单/排程在第一次选择工艺路线时加载工序配置为独立快照：
 * 排程明细冗余 op_name 后，展示与顺延重算完全不依赖 route_ops 行，
 * 工艺路线模块后续编辑（含工序）不影响已生成的排程数据。
 * route_op_id 仅作历史溯源，允许悬空。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_schedules', function (Blueprint $table) {
            $table->string('op_name', 64)->nullable()->after('op_type')->comment('工序名（排程生成时冗余，独立快照）');
        });

        // 历史数据回填：优先取 route_ops.op_name，缺失时用 op_type 兜底
        DB::table('task_schedules')->orderBy('id')->chunkById(500, function ($schedules) {
            foreach ($schedules as $schedule) {
                $opName = DB::table('route_ops')->where('id', $schedule->route_op_id)->value('op_name');
                DB::table('task_schedules')->where('id', $schedule->id)->update([
                    'op_name' => $opName ?: $schedule->op_type,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('task_schedules', function (Blueprint $table) {
            $table->dropColumn('op_name');
        });
    }
};
