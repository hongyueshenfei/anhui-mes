<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * prod_tasks（生产任务 —— 排产对象，产品级）—— 对应架构 §3.3
 *
 * - status: unscheduled/scheduled/in_progress/paused/done
 * - is_locked: 排产保存即锁定（解锁留痕，audit_logs action=unlock_task）
 * - plan_start/plan_end: 任务级计划起止（取第一/最后工序，排产时冗余同步）
 * - deviation_pct: 进度偏差%（实际完成 vs 计划，三期报工后计算）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prod_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_no', 32)->unique()->comment('任务号');
            $table->unsignedBigInteger('order_line_id');
            $table->foreign('order_line_id')->references('id')->on('order_lines')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unsignedBigInteger('route_id')->nullable()->comment('排产时锁定的工艺版本');
            $table->unsignedBigInteger('mold_id')->nullable()->comment('选定/默认模具（三匹配校验）');
            $table->integer('qty')->comment('任务数量');
            $table->integer('done_qty')->default(0)->comment('累计合格完成（报工汇总冗余，可对账）');
            $table->string('status', 16)->default('unscheduled')->index()->comment('unscheduled/scheduled/in_progress/paused/done');
            $table->unsignedTinyInteger('priority')->default(3)->comment('0-5，数值越小越急，P0最紧急');
            $table->date('delivery_date')->nullable()->index()->comment('交期（冗余自订单行，可单任务改）');
            $table->decimal('deviation_pct', 5, 2)->default(0)->comment('进度偏差%');
            $table->dateTime('plan_start')->nullable()->comment('任务级计划开始（第一工序）');
            $table->dateTime('plan_end')->nullable()->comment('任务级计划结束（最后工序）');
            $table->tinyInteger('is_locked')->default(0)->comment('排产锁定（保存即锁定）');
            $table->string('source', 16)->default('manual')->comment('manual/excel/k3');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prod_tasks');
    }
};
