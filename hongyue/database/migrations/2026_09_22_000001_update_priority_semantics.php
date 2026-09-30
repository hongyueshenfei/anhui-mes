<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 统一优先级口径：P0 最紧急，数值越小越紧急。
 * unsignedTinyInteger 本身已允许 0，这里主要同步已部署 MySQL 的字段注释。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['orders', 'order_lines', 'prod_tasks'] as $table) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `priority` TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '0-5，数值越小越急，P0最紧急'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['orders', 'order_lines', 'prod_tasks'] as $table) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `priority` TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '1-5 越大越急'");
        }
    }
};
