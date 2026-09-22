<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * teams（班组，含车间）—— 对应架构 §3.1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->comment('班组名：如 注塑车间一班');
            $table->string('workshop', 32)->comment('车间：注塑/吹塑/喷漆/效果/包装');
            $table->unsignedBigInteger('leader_user_id')->nullable()->comment('班组长');
            $table->foreign('leader_user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('shift_mode', 16)->default('two_shift')->comment('班制：two_shift/day');
            $table->tinyInteger('is_manual_line')->default(0)->comment('1=人工线，0=机台线');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};