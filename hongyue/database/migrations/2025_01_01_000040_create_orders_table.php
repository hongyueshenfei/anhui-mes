<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * orders（订单头）+ order_lines（订单明细）—— 对应架构 §3.3
 *
 * - src: manual(手工)/excel(导入)/mini_program/k3(四期拉单)
 * - status: pending(待排产)/scheduled/in_progress/produced/delivered
 * - 明细行创建后同步生成 prod_tasks（产品级生产任务，排产对象）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 32)->unique()->comment('业务单号');
            $table->string('src', 16)->default('manual')->comment('manual/excel/mini_program/k3');
            $table->string('k3_bill_no', 32)->nullable()->comment('金蝶生产订单号（四期拉单后落）');
            $table->string('customer_name', 128)->default('')->comment('客户名称');
            $table->string('status', 16)->default('pending')->index()->comment('pending/scheduled/in_progress/produced/delivered');
            $table->unsignedTinyInteger('priority')->default(3)->index()->comment('0-5，数值越小越急，P0最紧急');
            $table->date('delivery_date')->nullable()->comment('订单级期望交期（冗余，明细行可覆盖）');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->integer('qty')->comment('数量');
            $table->date('delivery_date')->nullable()->comment('交期');
            $table->unsignedTinyInteger('priority')->default(3)->comment('0-5，数值越小越急，P0最紧急');
            $table->string('status', 16)->default('pending')->comment('pending/scheduled/in_progress/done');
            $table->integer('done_qty')->default(0)->comment('完成数（报工汇总冗余，可对账）');
            $table->unsignedBigInteger('mold_id')->nullable()->comment('指定模具（可选，排产三匹配校验时确定）');
            $table->integer('line_no')->default(1)->comment('明细行号（Excel 导入原始行号参考）');
            $table->timestamps();
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
