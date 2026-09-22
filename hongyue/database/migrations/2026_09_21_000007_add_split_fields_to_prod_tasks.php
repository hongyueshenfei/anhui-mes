<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prod_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_task_id')->nullable()->after('id')->comment('拆单父任务；子任务指向原任务');
            $table->unsignedTinyInteger('split_index')->nullable()->comment('拆单子任务序号');
            $table->unsignedTinyInteger('split_total')->nullable()->comment('拆单子任务总数');
            $table->tinyInteger('is_split_parent')->default(0)->index()->comment('1=仅作拆单容器，不参与排产/报工汇总');
            $table->foreign('parent_task_id')->references('id')->on('prod_tasks')->nullOnDelete();
            $table->index(['parent_task_id', 'split_index']);
        });
    }

    public function down(): void
    {
        Schema::table('prod_tasks', function (Blueprint $table) {
            $table->dropForeign(['parent_task_id']);
            $table->dropIndex(['parent_task_id', 'split_index']);
            $table->dropIndex(['is_split_parent']);
            $table->dropColumn(['parent_task_id', 'split_index', 'split_total', 'is_split_parent']);
        });
    }
};
