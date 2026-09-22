<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schedule_suggestions 排程建议（APS一期：建议层）
 *
 * 报工滞后/破交期时系统生成顺延建议，计划员确认后采纳——自动结果只是建议层，
 * 采纳走 ManualScheduleService 正常保存链路（版本快照+锁定），禁止报工回调直改排程。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16)->default('postpone')->comment('建议类型：postpone=报工滞后顺延');
            $table->unsignedBigInteger('trigger_report_id')->nullable()->comment('触发报工流水（work_reports）');
            $table->unsignedBigInteger('task_id')->nullable()->comment('主受影响任务（prod_tasks）');
            $table->json('affected')->comment('受影响排程明细JSON（schedule_id/task_no/原预计/新预计）');
            $table->json('delayed_orders')->comment('延期订单清单JSON（订单号/产品/原交期/新预计）');
            $table->string('reason', 255)->nullable()->comment('生成原因（偏差%超阈值/预计破交期）');
            $table->string('status', 16)->default('pending')->index()->comment('pending/accepted/dismissed');
            $table->unsignedBigInteger('processed_by')->nullable()->comment('处理人');
            $table->dateTime('processed_at')->nullable()->comment('处理时间');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人（系统生成为空）');
            $table->timestamps();
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_suggestions');
    }
};
