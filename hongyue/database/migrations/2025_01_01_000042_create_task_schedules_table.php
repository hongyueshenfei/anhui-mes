<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * task_schedules（工序排程明细 —— 甘特图数据源）—— 对应架构 §3.3
 *
 * - 成型工序 machine_id 必填；人工线工序 team_id 必填
 * - plan_start/plan_end 自动按产能+班制+换模计算
 * - source: manual/ai/insert/auto 变更来源
 * - is_interrupted: 插单打断时已完成部分留档（done_qty + 原时间段），剩余量重排新行
 * - 说明：UNIQUE(task_id, seq) 因打断留档机制放宽为普通索引（架构偏差，见迁移内注释）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->foreign('task_id')->references('id')->on('prod_tasks')->cascadeOnDelete();
            $table->integer('seq')->comment('工序序');
            $table->unsignedBigInteger('route_op_id')->comment('工艺路线工序');
            $table->string('op_type', 16)->comment('冗余自 route_op：molding_injection/.../pack');
            $table->unsignedBigInteger('machine_id')->nullable()->comment('机台（成型工序必填）');
            $table->foreign('machine_id')->references('id')->on('machines')->nullOnDelete();
            $table->unsignedBigInteger('team_id')->nullable()->comment('班组（人工线工序必填）');
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->unsignedBigInteger('mold_id')->nullable()->comment('成型工序模具（与 task.mold_id 一致）');
            $table->dateTime('plan_start')->comment('计划开始');
            $table->dateTime('plan_end')->comment('计划结束');
            $table->integer('qty')->comment('该工序计划数量');
            $table->string('status', 16)->default('pending')->index()->comment('pending/running/paused/done');
            $table->string('source', 16)->default('manual')->comment('manual/ai/insert/auto 变更来源');
            $table->tinyInteger('is_interrupted')->default(0)->comment('打断留档标记 1=已完成部分留档');
            $table->integer('done_qty')->default(0)->comment('打断留档数量（已完成部分）');
            $table->tinyInteger('is_overlap')->default(0)->comment('强制排入冲突标记 1=存在重叠（需显式确认）');
            $table->string('note', 255)->nullable()->comment('备注（强制排入原因/打断原因）');
            $table->timestamps();
            // 设计说明：架构定义 UNIQUE(task_id, seq)，但插单打断需"已完成部分留档（原行）+ 剩余量重排（新行）"
            // 同任务同工序可能并存两行，故改为普通索引（打断留档行 is_interrupted=1 区分）
            $table->index(['task_id', 'seq']);
            $table->index(['machine_id', 'plan_start']);
            $table->index(['team_id', 'plan_start']);
            $table->index('mold_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_schedules');
    }
};