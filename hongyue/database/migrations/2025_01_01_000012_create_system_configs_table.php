<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * system_configs（系统配置）—— 对应架构 §3.1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_configs', function (Blueprint $table) {
            $table->id();
            $table->string('config_key', 64)->unique()->comment('如 shift.two_shift / k3.poll_interval_min');
            $table->json('config_value')->comment('JSON 配置值');
            $table->string('remark', 255)->nullable()->comment('说明');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_configs');
    }
};