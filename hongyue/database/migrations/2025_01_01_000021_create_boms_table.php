<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * boms（BOM 头）+ bom_lines（BOM 明细）—— 对应架构 §3.2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->comment('所属产品');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->string('version', 16)->comment('版本号，如 V1.0');
            $table->string('status', 16)->default('draft')->index()->comment('draft/published');
            $table->string('k3_push_status', 16)->default('none')->comment('none/queued/pushed/failed（四期启用）');
            $table->dateTime('published_at')->nullable()->comment('发布时间');
            $table->unsignedBigInteger('published_by')->nullable()->comment('发布人');
            $table->foreign('published_by')->references('id')->on('users')->nullOnDelete();
            $table->string('remark', 255)->nullable()->comment('变更说明');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['product_id', 'version']);
        });

        Schema::create('bom_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bom_id');
            $table->foreign('bom_id')->references('id')->on('boms')->cascadeOnDelete();
            $table->integer('seq')->default(0)->comment('行序');
            $table->string('item_code', 64)->comment('材料编码（对齐金蝶物料）');
            $table->string('item_name', 128)->comment('材料名称');
            $table->decimal('qty', 12, 4)->default(1)->comment('用量');
            $table->string('unit', 16)->default('个')->comment('单位');
            $table->decimal('loss_rate', 5, 2)->default(0)->comment('损耗率 %');
            $table->tinyInteger('is_key')->default(0)->comment('是否主材（报价材料成本取主材）');
            $table->index('bom_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_lines');
        Schema::dropIfExists('boms');
    }
};