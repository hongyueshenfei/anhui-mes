<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * schedule_versions（排产版本 —— 回滚支撑）—— 对应架构 §3.3
 *
 * - type: manual/ai/insert（来源：手工保存/智能排产/插单）
 * - status: draft/applied/rolled_back
 * - snapshot: task_schedules 全量快照 {before: [...], after: [...]}
 * - affected_orders: 插单时受影响订单延期清单 [{order_id, order_no, task_id, task_no, delay_hours, old_end, new_end, reason}]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_versions', function (Blueprint $table) {
            $table->id();
            $table->integer('version_no')->comment('自增版本号');
            $table->string('type', 16)->default('manual')->comment('manual/ai/insert');
            $table->string('status', 16)->default('applied')->index()->comment('draft/applied/rolled_back');
            $table->json('snapshot')->comment('task_schedules 全量快照（before/after）');
            $table->json('affected_orders')->nullable()->comment('插单受影响订单延期清单');
            $table->string('biz_key', 64)->nullable()->index()->comment('业务关联键（如 insert:task:123 / ai:job:xxx）');
            $table->unsignedBigInteger('created_by')->nullable()->comment('操作人');
            $table->dateTime('applied_at')->nullable()->comment('生效时间');
            $table->dateTime('rolled_back_at')->nullable()->comment('回滚时间');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_versions');
    }
};