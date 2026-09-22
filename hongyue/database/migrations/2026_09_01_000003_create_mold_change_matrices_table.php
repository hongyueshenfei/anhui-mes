<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mold_change_matrices 换模时间矩阵（APS一期）
 *
 * 按「前序模具 → 当前模具」记录换模耗时与禁止切换（如黑料→白料需清机）。
 * 解析优先级：本矩阵 > route_ops.mold_change_min > machines.mold_change_min > 0（见 ChangeoverService）。
 * 复用既有 mold_machines 做模具-机台匹配，本表只承载换模时间维度。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mold_change_matrices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_mold_id')->comment('前序模具');
            $table->foreign('from_mold_id')->references('id')->on('molds')->cascadeOnDelete();
            $table->unsignedBigInteger('to_mold_id')->comment('当前模具');
            $table->foreign('to_mold_id')->references('id')->on('molds')->cascadeOnDelete();
            $table->integer('changeover_minutes')->default(0)->comment('换模耗时(分钟)');
            $table->tinyInteger('is_forbidden')->default(0)->comment('禁止切换 1=拒绝排入（如黑料→白料）');
            $table->string('remark', 255)->nullable()->comment('备注（颜色/材质切换原因）');
            $table->unsignedBigInteger('created_by')->nullable()->comment('创建人');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('更新人');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['from_mold_id', 'to_mold_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mold_change_matrices');
    }
};
