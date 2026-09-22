<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * products（产品档案）—— 对应架构 §3.2
 * default_mold_id / default_route_id 因循环引用仅建索引，应用层校验。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique()->comment('与金蝶物料编码一致（金蝶为主映射）');
            $table->string('name', 128)->index()->comment('产品名称');
            $table->string('spec', 255)->nullable()->comment('规格');
            $table->string('unit', 16)->default('件')->comment('单位');
            $table->string('status', 16)->default('developing')->index()->comment('developing/trial/mass/obsolete');
            $table->unsignedBigInteger('default_mold_id')->nullable()->index()->comment('默认模具（应用层校验）');
            $table->unsignedBigInteger('default_route_id')->nullable()->index()->comment('默认工艺路线（应用层校验）');
            $table->decimal('weight', 10, 3)->nullable()->comment('单件重量(g)，报价材料估算用');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};