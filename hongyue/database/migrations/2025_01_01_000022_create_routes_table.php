<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * routes（工艺路线头）+ route_ops（工艺路线工序）—— 对应架构 §3.2
 * route_ops 为排产与报价共用的产能参数源。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->comment('所属产品');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->string('version', 16)->comment('版本号');
            $table->string('status', 16)->default('draft')->index()->comment('draft/published');
            $table->string('remark', 255)->nullable()->comment('变更说明');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['product_id', 'version']);
        });

        Schema::create('route_ops', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('route_id');
            $table->foreign('route_id')->references('id')->on('routes')->cascadeOnDelete();
            $table->integer('seq')->comment('工序顺序');
            $table->string('op_type', 16)->index()->comment('molding_injection/molding_blow/paint/effect/pack');
            $table->string('op_name', 64)->comment('工序名');
            $table->decimal('capacity_per_hour', 10, 2)->nullable()->comment('标准产能（件/小时），NULL=取机台/班组默认');
            $table->integer('mold_change_min')->nullable()->comment('换模分钟（成型工序）');
            $table->string('shift_mode', 16)->nullable()->comment('班制，NULL=取机台默认');
            $table->string('machine_type', 16)->nullable()->comment('可用机台类型（成型工序）');
            $table->unsignedBigInteger('team_id')->nullable()->comment('人工线班组（喷漆/效果/包装）');
            $table->foreign('team_id')->references('id')->on('teams')->nullOnDelete();
            $table->string('rate_key', 32)->nullable()->comment('报价费率键 process_rates.rate_key');
            $table->timestamps();
            $table->unique(['route_id', 'seq']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_ops');
        Schema::dropIfExists('routes');
    }
};