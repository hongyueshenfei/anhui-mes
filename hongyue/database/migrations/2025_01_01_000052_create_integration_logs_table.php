<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * integration_logs（集成日志 —— 所有外部调用留痕）—— 对应架构 §3.7
 *
 * - 幂等键：api_type + biz_type + biz_id（成功记录唯一，重推前查重）
 * - target: k3/mini_program/ai；api_type: push_stock_in/ai_schedule/...
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 8)->default('out')->comment('in=拉取 out=推送');
            $table->string('target', 16)->index()->comment('k3/mini_program/ai');
            $table->string('api_type', 32)->comment('push_stock_in/pull_prod_order/...');
            $table->string('biz_type', 32)->nullable()->comment('业务类型（幂等键组成）');
            $table->unsignedBigInteger('biz_id')->nullable()->comment('业务 ID（幂等键组成）');
            $table->json('request_body')->nullable()->comment('报文留痕（敏感字段脱敏）');
            $table->json('response_body')->nullable()->comment('响应留痕');
            $table->string('status', 16)->index()->comment('success/failed/retry');
            $table->tinyInteger('retry_count')->default(0)->comment('最大重试 5 次 + 手动');
            $table->string('error_msg', 512)->nullable();
            $table->dateTime('created_at')->comment('发起时间');
            $table->dateTime('finished_at')->nullable();
            $table->index(['api_type', 'biz_type', 'biz_id']);
            $table->index(['api_type', 'biz_type', 'biz_id', 'status'], 'integration_idempotent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};