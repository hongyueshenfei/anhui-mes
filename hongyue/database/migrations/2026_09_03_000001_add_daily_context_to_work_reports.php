<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 报工补充日结上下文。
 * 工作日与班次用于区分“晚上集中报工”与实际作业日期；幂等键用于离线补传防重复。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->date('work_date')->nullable()->after('report_time')->comment('实际作业日期（日结归属）');
            $table->string('shift_code', 16)->nullable()->after('work_date')->comment('班次：day/night');
            $table->string('client_request_id', 64)->nullable()->after('shift_code')->unique()->comment('客户端幂等键');
            $table->index(['work_date', 'shift_code', 'worker_id'], 'work_reports_daily_worker_idx');
        });
    }

    public function down(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->dropIndex('work_reports_daily_worker_idx');
            $table->dropUnique(['client_request_id']);
            $table->dropColumn(['work_date', 'shift_code', 'client_request_id']);
        });
    }
};
