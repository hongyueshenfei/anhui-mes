<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * route_ops 新增「标准工时 / 工序负责人 / 备注」（2026-08-31 订单级工艺明细）
 *
 * 订单编辑界面可针对当前工单调整工序的标准工时/负责人/备注；
 * 工艺路线库（route_ops）同步支持这三列，供 PLM 工艺编辑复用。
 * 全部可空，仅作记录字段，不影响排产/报价既有逻辑。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_ops', function (Blueprint $table) {
            $table->decimal('std_hours', 10, 2)->nullable()->after('workshop_id')->comment('标准工时（小时）');
            $table->unsignedBigInteger('owner_id')->nullable()->after('std_hours')->comment('工序负责人');
            $table->foreign('owner_id')->references('id')->on('users')->nullOnDelete();
            $table->index('owner_id');
            $table->string('remark', 255)->nullable()->after('owner_id')->comment('备注');
        });
    }

    public function down(): void
    {
        Schema::table('route_ops', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['owner_id']);
            $table->dropColumn(['std_hours', 'owner_id', 'remark']);
        });
    }
};