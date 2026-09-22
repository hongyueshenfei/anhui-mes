<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 红灯关闭记录（四期红灯看板持久化）
 *
 * 红灯规则引擎实时计算（不建物化表），关闭动作写入本表（唯一键 source+task_id），
 * 重算时跳过已关闭项；同时写 audit_logs（module=dashboard, action=red_light_close）留审计。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('red_light_closes', function (Blueprint $table) {
            $table->id();
            $table->string('source', 24)->comment('unscheduled/no_report/overdue/behind');
            $table->unsignedBigInteger('task_id')->comment('关联生产任务');
            $table->string('closed_by', 64)->nullable()->comment('关闭人（姓名）');
            $table->unsignedBigInteger('user_id')->nullable()->comment('关闭人用户ID');
            $table->string('reason', 512)->nullable()->comment('关闭原因/处理结果');
            $table->timestamp('created_at')->nullable();
            $table->unique(['source', 'task_id'], 'uq_red_light_close_source_task');
            $table->index(['task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('red_light_closes');
    }
};
