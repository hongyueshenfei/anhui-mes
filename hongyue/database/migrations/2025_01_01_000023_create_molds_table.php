<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * molds（模具台账）及关联表 —— 对应架构 §3.2
 * - mold_product 模具-产品 多对多
 * - mold_machines 模具-适用机台 多对多
 * - mold_maintain_records 保养/维修记录
 * - mold_life_logs 寿命流水（只增不改）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('molds', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique()->comment('模具编码');
            $table->string('name', 128)->comment('模具名称');
            $table->string('type', 16)->index()->comment('injection/blow');
            $table->string('status', 16)->default('in_use')->index()->comment('in_use/idle/repair/scrapped');
            $table->string('location', 64)->nullable()->comment('存放位置');
            $table->integer('cavity_count')->default(1)->comment('模穴数（寿命折算：开模次数=报工数÷模穴数）');
            $table->integer('lifetime_openings')->default(0)->comment('设计寿命（额定开模次数）');
            $table->integer('opened_count')->default(0)->comment('累计开模次数（报工事件累加）');
            $table->decimal('purchase_cost', 12, 2)->nullable()->comment('模具造价（报价摊销用）');
            $table->date('purchase_date')->nullable()->comment('购置日期');
            $table->string('vendor', 128)->nullable()->comment('供应商');
            $table->integer('maintain_cycle_days')->nullable()->comment('保养周期（天）');
            $table->dateTime('last_maintain_at')->nullable()->comment('上次保养时间');
            $table->dateTime('next_maintain_at')->nullable()->comment('下次保养时间（到期提醒）');
            $table->text('notes')->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('mold_product', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mold_id');
            $table->foreign('mold_id')->references('id')->on('molds')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->tinyInteger('is_default')->default(0)->comment('是否默认模具');
            $table->timestamps();
            $table->unique(['mold_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('mold_machines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mold_id');
            $table->foreign('mold_id')->references('id')->on('molds')->cascadeOnDelete();
            $table->unsignedBigInteger('machine_id');
            $table->foreign('machine_id')->references('id')->on('machines')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['mold_id', 'machine_id']);
            $table->index('machine_id');
        });

        Schema::create('mold_maintain_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mold_id');
            $table->foreign('mold_id')->references('id')->on('molds')->cascadeOnDelete();
            $table->string('type', 16)->default('maintain')->comment('maintain/repair');
            $table->dateTime('at')->comment('发生时间');
            $table->decimal('cost', 12, 2)->default(0)->comment('费用');
            $table->string('vendor', 128)->nullable()->comment('供应商/责任人');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->index('mold_id');
            $table->timestamps();
        });

        Schema::create('mold_life_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('mold_id');
            $table->foreign('mold_id')->references('id')->on('molds')->cascadeOnDelete();
            $table->unsignedBigInteger('task_id')->nullable()->comment('生产任务（二期起）');
            $table->unsignedBigInteger('work_report_id')->nullable()->comment('报工流水（三期起）');
            $table->integer('opening_count')->comment('本次开模次数（=合格数÷模穴数）');
            $table->dateTime('created_at')->comment('发生时间');
            $table->index('mold_id');
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mold_life_logs');
        Schema::dropIfExists('mold_maintain_records');
        Schema::dropIfExists('mold_machines');
        Schema::dropIfExists('mold_product');
        Schema::dropIfExists('molds');
    }
};