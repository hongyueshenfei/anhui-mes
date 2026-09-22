<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;

/**
 * 修正 route_ops.op_type 列长：原 16 无法容纳 molding_injection(18) 等取值，
 * 在 MySQL 严格模式下插入报 1406。SQLite 不强制长度，无需处理。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection() instanceof MySqlConnection) {
            DB::statement('ALTER TABLE route_ops MODIFY op_type VARCHAR(24)');
        }
    }

    public function down(): void
    {
        if (DB::connection() instanceof MySqlConnection) {
            DB::statement('ALTER TABLE route_ops MODIFY op_type VARCHAR(16)');
        }
    }
};
