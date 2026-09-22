<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 消息通知表（架构 §3.7 notifications）
 *
 * 企业微信应用消息（红灯/临期推送责任人）：mock_mode 沙箱模拟发送落库；
 * 真实推送失败仅留 failed 记录（不阻断业务）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->comment('接收人');
            $table->string('channel', 16)->default('wecom')->comment('wecom/mini_program');
            $table->string('template', 64)->nullable()->comment('red_light/due_soon/...');
            $table->string('title', 128)->nullable();
            $table->text('content')->nullable();
            $table->string('status', 16)->default('pending')->comment('pending/sent/failed');
            $table->string('target_type', 32)->nullable()->comment('task/order');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('error_msg', 512)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['template']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
