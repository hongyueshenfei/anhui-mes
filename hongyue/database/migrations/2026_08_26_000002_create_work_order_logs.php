<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 工单操作日志（编辑/开始/完结/撤销 留痕）+ 工单开始/完结时间
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->string('action', 24)->comment('edit/start/complete/revoke');
            $table->string('from_status', 16)->nullable()->comment('动作前订单状态');
            $table->string('to_status', 16)->nullable()->comment('动作后订单状态');
            $table->unsignedBigInteger('operator_id')->nullable()->comment('操作人');
            $table->string('operator_name', 64)->nullable()->comment('操作人姓名快照');
            $table->string('remark', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index('order_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->comment('工单开始生产时间');
            $table->timestamp('completed_at')->nullable()->comment('工单完结时间');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'completed_at']);
        });
        Schema::dropIfExists('work_order_logs');
    }
};
