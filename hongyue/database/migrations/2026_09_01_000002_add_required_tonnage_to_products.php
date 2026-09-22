<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * products 增加 required_tonnage 所需最小锁模力（APS一期：吨位硬约束）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('required_tonnage')->nullable()->after('weight')
                ->comment('所需最小锁模力(吨)，排产吨位校验用');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('required_tonnage');
        });
    }
};
