<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 移除发货管理模块后清理其数据表（shipments / shipment_lines）。
 * 原建表迁移 2025_01_01_000062 已删除，此处幂等清理，避免遗留无用表。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('shipments');
    }

    public function down(): void
    {
        // 发货模块已整体移除，down 不再重建。
    }
};
