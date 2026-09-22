<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * task_schedules.op_type 加宽（route_ops 已加宽的配套遗漏）
 *
 * 背景：op_type 从 molding/paint 等短键扩展为 molding_injection/molding_blow/
 * paint/effect/pack（2026_08_27_000002 widen_route_ops_op_type 只改 route_ops），
 * task_schedules.op_type varchar(16) 装不下 molding_injection(17) → MySQL 严格模式 1406。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_schedules', function (Blueprint $table) {
            $table->string('op_type', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('task_schedules', function (Blueprint $table) {
            $table->string('op_type', 16)->change();
        });
    }
};