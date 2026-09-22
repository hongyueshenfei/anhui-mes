<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 物料主数据表（四期物料同步 K4）
 *
 * 金蝶 ExecuteBillQuery 拉取物料列表 → materials upsert（编码唯一）；
 * 未匹配提示：products.code 在 materials 中不存在 → 前端给出未匹配清单。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique()->comment('物料编码（对齐金蝶）');
            $table->string('name', 128)->comment('物料名称');
            $table->string('spec', 255)->nullable()->comment('规格');
            $table->string('unit', 16)->nullable()->comment('基本单位');
            $table->string('category', 64)->nullable()->comment('物料分类');
            $table->string('status', 16)->default('active')->comment('active/disabled');
            $table->timestamp('k3_updated_at')->nullable()->comment('金蝶侧最后修改时间（游标）');
            $table->timestamps();
            $table->index(['name']);
            $table->index(['category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
