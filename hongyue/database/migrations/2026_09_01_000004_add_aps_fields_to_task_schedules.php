<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * task_schedules 增加 APS 滚动字段（APS一期）
 *
 * - forecast_start/forecast_end：预计起止，随报工滚动（首次保存 = plan；plan 永不被自动修改）
 * - actual_start/actual_end：实际起止，由报工推断（首笔正报工=开始，累计合格达工序量=结束，
 *   与 OrderService::attachTaskExecutionMeta 口径一致）
 * - is_frozen：时间窗冻结标记（未来 N 天内不可自动顺延，不限制计划员手工重排）
 * - changeover_minutes：排入时实际采用的换模分钟数（矩阵>工序>机台，留档）
 * 注意：mold_id 建表时已存在，不重复添加。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_schedules', function (Blueprint $table) {
            $table->dateTime('forecast_start')->nullable()->after('plan_end')
                ->comment('APS预计开始（随报工滚动，初始=plan）');
            $table->dateTime('forecast_end')->nullable()->after('forecast_start')
                ->comment('APS预计结束（随报工滚动，初始=plan）');
            $table->dateTime('actual_start')->nullable()->after('forecast_end')
                ->comment('实际开始（首笔正报工时刻）');
            $table->dateTime('actual_end')->nullable()->after('actual_start')
                ->comment('实际结束（累计合格达工序量时刻）');
            $table->tinyInteger('is_frozen')->default(0)->after('actual_end')
                ->comment('冻结区标记 1=未来N天内不可自动顺延');
            $table->integer('changeover_minutes')->default(0)->after('is_frozen')
                ->comment('排入时实际采用的换模分钟数（矩阵>工序>机台）');
        });
    }

    public function down(): void
    {
        Schema::table('task_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'forecast_start',
                'forecast_end',
                'actual_start',
                'actual_end',
                'is_frozen',
                'changeover_minutes',
            ]);
        });
    }
};
