<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 工艺路线工序（route_ops）补强「工序默认资源」：
     * - default_machine_id：成型工序（注塑/吹塑）必选的机台
     * - default_line_id：人工线工序（喷漆/效果/包装）必选的产线（machines 表 line 行）
     *
     * 均为可空外键（指向 machines.id，删除机台时置 null），用于「工艺路线按车间选机台/产线」。
     */
    public function up(): void
    {
        Schema::table('route_ops', function (Blueprint $t) {
            $t->unsignedBigInteger('default_machine_id')->nullable()->after('team_id');
            $t->unsignedBigInteger('default_line_id')->nullable()->after('default_machine_id');
            $t->foreign('default_machine_id')->references('id')->on('machines')->nullOnDelete();
            $t->foreign('default_line_id')->references('id')->on('machines')->nullOnDelete();
            $t->index('default_machine_id');
            $t->index('default_line_id');
        });
    }

    public function down(): void
    {
        Schema::table('route_ops', function (Blueprint $t) {
            $t->dropForeign(['default_machine_id']);
            $t->dropForeign(['default_line_id']);
            $t->dropIndex(['default_machine_id']);
            $t->dropIndex(['default_line_id']);
            $t->dropColumn(['default_machine_id', 'default_line_id']);
        });
    }
};
