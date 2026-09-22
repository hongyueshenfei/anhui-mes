<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * op_types（工序类型字典）—— 工序类型可维护化
 *
 * 背景：工序类型此前硬编码在 RouteService::opTemplates()（5 个固定项），
 * 无法新增/改名/停用。建字典表后 opTemplates() 改从表读取（is_active=1），
 * 工艺路线编排的下拉选项自动跟随字典。
 *
 * 字段语义与 RouteOp::resourceType() / workshopForOpType() 对齐：
 * - is_molding：是否成型工序（需机台/换模）
 * - machine_type：成型工序的机台类型（injection/blow），非成型为 null
 * - workshop：适用车间（injection/blow/paint/effect/pack），决定机台/产线候选
 * - sort：展示排序；is_active：停用后不再出现在下拉（历史 route_ops 不受影响）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('op_types', function (Blueprint $table) {
            $table->id();
            $table->string('op_type', 32)->unique()->comment('工序类型键（route_ops.op_type 引用）');
            $table->string('op_name', 64)->comment('工序类型名称');
            $table->tinyInteger('is_molding')->default(0)->comment('是否成型工序（1=需机台/换模）');
            $table->string('machine_type', 16)->nullable()->comment('成型工序机台类型 injection/blow');
            $table->string('workshop', 16)->index()->comment('适用车间 injection/blow/paint/effect/pack');
            $table->integer('sort')->default(0)->comment('展示排序（小在前）');
            $table->tinyInteger('is_active')->default(1)->index()->comment('1=启用 0=停用');
            $table->string('remark', 255)->nullable()->comment('说明');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_types');
    }
};
