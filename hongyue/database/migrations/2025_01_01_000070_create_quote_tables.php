<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 报价单（架构 §3.6 quotes / quote_lines）—— 五期
 *
 * quotes：草稿/已发/成交/未成交；调价留痕；suggest_delivery 建议交期联动产能负荷。
 * quote_lines：成本构成（单位成本：材料/加工/摊销/损耗）可展开；suggest_price 建议单价；
 *              anomaly_flag 偏离历史成交 ±30% 提示；ai_assist LLM 辅助状态。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_no', 32)->unique()->comment('报价单号');
            $table->string('customer_name', 128);
            $table->string('source', 16)->default('sales')->comment('sales/mini_program');
            $table->string('status', 16)->default('draft')->comment('draft/sent/won/lost');
            $table->decimal('target_margin', 5, 2)->default(20)->comment('目标毛利率 %');
            $table->date('suggest_delivery')->nullable()->comment('建议交期');
            $table->decimal('final_price', 12, 2)->nullable()->comment('成交价');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('remark', 512)->nullable();
            $table->timestamps();
            $table->index(['status']);
            $table->index(['created_by']);
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quote_id');
            $table->string('spec_text', 512)->comment('客户规格描述');
            $table->integer('qty')->default(0)->comment('预估数量');
            $table->unsignedBigInteger('ref_product_id')->nullable()->comment('近似老品参照');
            $table->unsignedBigInteger('product_id')->nullable()->comment('匹配产品（可空=仅规格估价）');
            $table->decimal('material_cost', 12, 4)->default(0)->comment('单位材料成本');
            $table->decimal('process_cost', 12, 4)->default(0)->comment('单位加工成本');
            $table->decimal('mold_amort', 12, 4)->default(0)->comment('单位模具摊销');
            $table->decimal('loss_cost', 12, 4)->default(0)->comment('单位损耗成本');
            $table->decimal('total_cost', 12, 4)->default(0)->comment('单位总成本（含损耗）');
            $table->decimal('margin', 5, 2)->default(20)->comment('毛利率 %');
            $table->decimal('suggest_price', 12, 2)->default(0)->comment('建议单价');
            $table->integer('delivery_days')->default(0)->comment('建议交期（天）');
            $table->string('llm_remark', 512)->nullable()->comment('LLM 报价说明');
            $table->string('ai_assist', 16)->default('not_enabled')->comment('enabled/not_enabled');
            $table->boolean('anomaly_flag')->default(false)->comment('偏离历史成交 ±30% 提示');
            $table->index(['quote_id']);
            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
    }
};
