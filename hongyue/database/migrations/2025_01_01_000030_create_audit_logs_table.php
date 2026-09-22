<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs（操作审计）—— 对应架构 §3.7
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('操作人');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('module', 32)->comment('模块：plm/system/order/...');
            $table->string('action', 32)->comment('动作：create/update/delete/publish_bom/...');
            $table->string('target_type', 64)->nullable()->comment('对象类型');
            $table->unsignedBigInteger('target_id')->nullable()->comment('对象 ID');
            $table->json('detail')->nullable()->comment('详情（参数/结果）');
            $table->string('ip', 45)->nullable()->comment('来源 IP');
            $table->dateTime('created_at')->comment('时间');
            $table->index(['user_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};