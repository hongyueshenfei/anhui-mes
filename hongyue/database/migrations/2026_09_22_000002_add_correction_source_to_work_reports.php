<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为冲减流水记录原报工来源。
 *
 * work_reports 仍然只允许追加记录；该字段只建立“冲减记录 → 原正报工”的审计关系，
 * 不回写、不删除历史流水。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('correction_of_report_id')
                ->nullable()
                ->after('sign')
                ->comment('冲减对应的原正报工流水；历史冲减可为空');
            $table->foreign('correction_of_report_id')
                ->references('id')
                ->on('work_reports')
                ->nullOnDelete();
            $table->index('correction_of_report_id', 'work_reports_correction_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('work_reports', function (Blueprint $table) {
            $table->dropForeign(['correction_of_report_id']);
            $table->dropIndex('work_reports_correction_source_idx');
            $table->dropColumn('correction_of_report_id');
        });
    }
};
