<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * work_reports（报工流水 —— 只增不改）—— 对应架构 §3.4
 *
 * 台账纪律（D3）：仅 INSERT；更正用冲减负记录（sign=-1 且 remark 必填）。
 * - qty 合格数 / defect_qty 次品数（冲减时为负）
 * - report_by 操作人 / worker_id 实际作业人（代报必选）
 * - source: scan/click/leader_report/makeup/offline
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->foreign('task_id')->references('id')->on('prod_tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('schedule_id')->nullable()->comment('对应工序排程（可空=任务级报工）');
            $table->unsignedBigInteger('product_id')->comment('冗余产品');
            $table->unsignedBigInteger('machine_id')->nullable()->comment('冗余机台');
            $table->integer('qty')->default(0)->comment('合格数（冲减时为负）');
            $table->integer('defect_qty')->default(0)->comment('次品数（冲减时为负）');
            $table->tinyInteger('sign')->default(1)->comment('1=正常 -1=冲减（更正原记录）');
            $table->unsignedBigInteger('report_by')->comment('操作人');
            $table->foreign('report_by')->references('id')->on('users');
            $table->unsignedBigInteger('worker_id')->nullable()->comment('实际作业人（代报必选）');
            $table->foreign('worker_id')->references('id')->on('users')->nullOnDelete();
            $table->dateTime('report_time')->comment('报工时间');
            $table->string('source', 16)->default('click')->comment('scan/click/leader_report/makeup/offline');
            $table->tinyInteger('offline_flag')->default(0)->comment('弱网离线暂存补传标记');
            $table->string('remark', 255)->nullable()->comment('冲减原因必填');
            $table->timestamps();
            $table->index(['task_id', 'report_time']);
            $table->index('worker_id');
            $table->index('report_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_reports');
    }
};