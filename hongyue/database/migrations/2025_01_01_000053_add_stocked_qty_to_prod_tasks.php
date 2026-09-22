<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prod_tasks.stocked_qty（已入库数量冗余，三期分批入库）—— 架构偏差说明：
 * 架构 §3.3 未定义该列，但 PC 入库管理与订单可发货量（Σ已入库）需要高频读取；
 * 采用冗余列 + 每次入库事务内同步，可对账（Σstock_ins.qty 一致）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prod_tasks', function (Blueprint $table) {
            $table->integer('stocked_qty')->default(0)->after('done_qty')->comment('已入库数量（分批入库冗余，可对账）');
        });
    }

    public function down(): void
    {
        Schema::table('prod_tasks', function (Blueprint $table) {
            $table->dropColumn('stocked_qty');
        });
    }
};