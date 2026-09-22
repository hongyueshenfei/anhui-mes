<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * stock_ins（生产入库单 —— 分批入库）—— 对应架构 §3.4
 *
 * - 可入库量 = Σ报工合格数 − Σ已入库量（入库时服务端强校验，超量 422）
 * - k3_push_status: queued/pushing/success/failed/retry；k3_bill_no 金蝶回执
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ins', function (Blueprint $table) {
            $table->id();
            $table->string('ins_no', 32)->unique()->comment('入库单号（分批独立）');
            $table->unsignedBigInteger('task_id');
            $table->foreign('task_id')->references('id')->on('prod_tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->comment('冗余产品');
            $table->integer('qty')->comment('本次入库量');
            $table->string('ins_status', 16)->default('confirmed')->comment('draft/confirmed/pushed');
            $table->string('k3_push_status', 16)->default('queued')->index()->comment('queued/pushing/success/failed/retry');
            $table->string('k3_bill_no', 32)->nullable()->comment('金蝶生产入库单号');
            $table->json('k3_result')->nullable()->comment('金蝶回执');
            $table->unsignedBigInteger('created_by')->nullable()->comment('操作人');
            $table->string('remark', 255)->nullable();
            $table->timestamps();
            $table->index('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ins');
    }
};