<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 报工流水增加实际作业时间段。
 *
 * 旧流水无法反推真实持续时长，因此用原 report_time 回填为同一时刻，
 * 仅作为兼容性的时间点；新报工必须由界面录入开始、结束时间。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->dateTime('start_time')->nullable()->after('report_time')->comment('本次实际作业开始时间');
            $table->dateTime('end_time')->nullable()->after('start_time')->comment('本次实际作业结束时间');
            $table->index(['schedule_id', 'start_time', 'end_time'], 'work_reports_schedule_time_range_idx');
        });

        DB::table('work_reports')
            ->whereNull('start_time')
            ->update(['start_time' => DB::raw('report_time')]);
        DB::table('work_reports')
            ->whereNull('end_time')
            ->update(['end_time' => DB::raw('report_time')]);
    }

    public function down(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->dropIndex('work_reports_schedule_time_range_idx');
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
