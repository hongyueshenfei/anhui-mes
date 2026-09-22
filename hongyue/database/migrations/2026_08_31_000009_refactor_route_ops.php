<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * route_ops 重构（2026-08-31 业务确认「编辑工艺优化」）
 *
 * 1. 删除 capacity_per_hour：工艺路线不再承载产能，理论产能由排产选定机台后按机台产能计算。
 *    排产（ScheduleEngine/AutoSchedule/GanttData）与报价（QuoteRuleEngine）均已具备
 *    「工序产能为空 → 取机台产能」兜底，删除该列后自动走机台侧，不影响其他模块。
 * 2. 新增 workshop_id：工序「生产车间」改为从车间列表显式选择（取代原 machine_type
 *    硬编码注塑/吹塑），NULL 时按工序类型字典推导。
 * 3. seq 连续化：历史数据按路线分组重排为 1/2/3/... 连续序号（原 10/20/30 步进）。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. 新增 workshop_id（车间实体化后工序的适用车间显式选择）
        Schema::table('route_ops', function (Blueprint $table) {
            $table->unsignedBigInteger('workshop_id')->nullable()->after('op_type')->comment('生产车间（工序显式指定；NULL=按工序类型字典推导）');
            $table->foreign('workshop_id')->references('id')->on('workshops')->nullOnDelete();
            $table->index('workshop_id');
        });

        // 2. 按工序类型回填车间（op_type → 字典/映射 → workshops.code → id）
        $workshopByOpType = [];
        foreach (\App\Models\OpType::all() as $t) {
            $workshopByOpType[$t->op_type] = $t->workshop;
        }
        $workshopIds = DB::table('workshops')->pluck('id', 'code');
        DB::table('route_ops')->orderBy('id')->chunkById(500, function ($ops) use ($workshopByOpType, $workshopIds) {
            foreach ($ops as $op) {
                $code = $workshopByOpType[$op->op_type] ?? \App\Models\RouteOp::workshopForOpType((string) $op->op_type);
                $wid = $code ? ($workshopIds[$code] ?? null) : null;
                if ($wid) {
                    DB::table('route_ops')->where('id', $op->id)->update(['workshop_id' => $wid]);
                }
            }
        });

        // 3. 删除产能列（排产/报价均按机台产能兜底）
        Schema::table('route_ops', function (Blueprint $table) {
            $table->dropColumn('capacity_per_hour');
        });

        // 4. seq 连续化：先整体平移避免 UNIQUE(route_id, seq) 冲突，再按路线分组重排 1..n
        DB::table('route_ops')->update(['seq' => DB::raw('seq + 1000000')]);
        $routeIds = DB::table('route_ops')->distinct()->pluck('route_id');
        foreach ($routeIds as $routeId) {
            $seq = 1;
            foreach (DB::table('route_ops')->where('route_id', $routeId)->orderBy('seq')->orderBy('id')->pluck('id') as $opId) {
                DB::table('route_ops')->where('id', $opId)->update(['seq' => $seq++]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('route_ops', function (Blueprint $table) {
            $table->dropForeign(['workshop_id']);
            $table->dropIndex(['workshop_id']);
            $table->dropColumn('workshop_id');
            $table->decimal('capacity_per_hour', 10, 2)->nullable()->after('op_name')->comment('标准产能（件/小时），NULL=取机台默认');
        });
    }
};
