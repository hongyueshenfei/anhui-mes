<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * machines 增加 tonnage 锁模力（APS一期：吨位硬约束）
 *
 * 排产校验：产品 required_tonnage > 机台 tonnage 时拒绝排入；
 * 任一为空则跳过校验（向后兼容存量数据）。产线无锁模力概念，保持 null。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->unsignedInteger('tonnage')->nullable()->after('mold_change_min')
                ->comment('锁模力(吨)，吨位硬约束：需≥产品 required_tonnage');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('tonnage');
        });
    }
};
