<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prod_tasks 新增订单级工序明细快照（2026-08-31 订单级工艺明细）
 *
 * route_ops_override JSON：当前工单编辑后的工序明细快照，
 * 结构等同 route_ops 的工序行（seq/op_type/op_name/workshop_id/machine_id/line_id/
 * std_hours/owner_id/remark）。
 * - NULL = 沿用工艺路线库（route_ops），其他工单不受影响；
 * - 非 NULL = 该任务已定制，后续展示/排产均以快照为准。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prod_tasks', function (Blueprint $table) {
            $table->json('route_ops_override')->nullable()->after('route_id')->comment('订单级工序明细快照（NULL=沿用工艺路线库）');
        });
    }

    public function down(): void
    {
        Schema::table('prod_tasks', function (Blueprint $table) {
            $table->dropColumn('route_ops_override');
        });
    }
};