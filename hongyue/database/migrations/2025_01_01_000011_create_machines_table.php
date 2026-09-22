<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * machines（机台档案）—— 对应架构 §3.1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique()->comment('机台编码：如 I-01、B-02');
            $table->string('name', 64)->comment('机台名称');
            $table->string('type', 16)->index()->comment('injection注塑/blow吹塑');
            $table->string('workshop', 32)->comment('所在车间');
            $table->decimal('capacity_per_hour', 10, 2)->default(0)->comment('机台侧默认标准产能（件/小时）');
            $table->integer('mold_change_min')->default(0)->comment('机台侧默认换模分钟数');
            $table->string('shift_mode', 16)->default('two_shift')->comment('默认班制：two_shift/day');
            $table->string('status', 16)->default('running')->index()->comment('running/stopped/maintenance');
            $table->tinyInteger('is_active')->default(1)->comment('1=启用 0=停用');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};