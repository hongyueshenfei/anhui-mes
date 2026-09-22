<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BOM 明细行序连续化（2026-08-31 业务确认）
 *
 * 历史种子/手工数据行序 seq 为 10/20/30 步进，统一按 BOM 分组重排为 1/2/3...
 * （与工艺路线工序 seq、工序类型 sort 的连续序号口径一致）。
 * 仅重排显示顺序，不改变材料明细内容。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 先整体平移避免潜在唯一键冲突，再按 BOM 分组重排 1..n
        DB::table('bom_lines')->update(['seq' => DB::raw('seq + 1000000')]);
        $bomIds = DB::table('bom_lines')->distinct()->pluck('bom_id');
        foreach ($bomIds as $bomId) {
            $seq = 1;
            foreach (DB::table('bom_lines')->where('bom_id', $bomId)->orderBy('seq')->orderBy('id')->pluck('id') as $lineId) {
                DB::table('bom_lines')->where('id', $lineId)->update(['seq' => $seq++]);
            }
        }
    }

    public function down(): void
    {
        // 数据修复不可逆：不提供回滚
    }
};
