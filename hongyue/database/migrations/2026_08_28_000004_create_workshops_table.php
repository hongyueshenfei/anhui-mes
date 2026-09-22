<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * workshops（车间）—— 车间实体化（用户需求：先有车间，车间下面是机台或产线）
 *
 * 背景：车间此前仅是 machines.workshop / teams.workshop 的字符串枚举，
 * 无可管理实体。建表后机台/产线挂 workshop_id，班组概念下线。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshops', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique()->comment('车间编码：injection/blow/paint/effect/pack');
            $table->string('name', 64)->comment('车间名称');
            $table->integer('sort')->default(0)->comment('展示排序（小在前）');
            $table->tinyInteger('is_active')->default(1)->index()->comment('1=启用 0=停用');
            $table->string('remark', 255)->nullable()->comment('说明');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshops');
    }
};
