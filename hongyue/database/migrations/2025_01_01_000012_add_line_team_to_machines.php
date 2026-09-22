<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * machines 资源模型扩展（客户确认「资源强对应」新业务规则）
 *
 * 1. machines.type 枚举扩展：在原 injection/blow 基础上新增
 *    - paint_line 喷漆产线（人工线，绑定喷漆组）
 *    - pack_line   包装产线（人工线，绑定包装组）
 *    SQLite 不支持修改列类型，且原列 string(16) 长度足以容纳新值
 *    （paint_line=10、pack_line=9），故不重建列；枚举约束由代码层强校验
 *    （Machine::TYPE_* 常量 + MachineRequest::rules() + ScheduleEngineService）。
 * 2. 新增 machines.team_id 可空：产线绑定的班组（排产/报工资源归属依据）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->after('workshop')->comment('产线绑定班组（paint_line/pack_line 资源归属），机台可为空');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('team_id');
        });
    }
};
