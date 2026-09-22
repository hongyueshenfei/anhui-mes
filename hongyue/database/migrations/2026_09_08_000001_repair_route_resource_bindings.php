<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 修复存量工艺路线的人工线资源绑定。
 *
 * 早期演示数据曾把「效果处理」绑定到喷漆线、把「包装」绑定到效果线。
 * 排程重新计算整条工序链时会重新读取 route_ops，因此拖动一个计划条也会暴露
 * 这个基础数据错误。绑定必须按工序类型和车间保持一一对应，不能在排产时静默换线。
 */
return new class extends Migration
{
    public function up(): void
    {
        $workshopIds = DB::table('workshops')
            ->whereIn('code', ['paint', 'effect', 'pack'])
            ->pluck('id', 'code');

        $lineIds = DB::table('machines')
            ->whereIn('type', ['paint_line', 'effect_line', 'pack_line'])
            ->where('is_active', 1)
            ->where('status', 'running')
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['id', 'type'])
            ->groupBy('type')
            ->map(fn ($machines) => (int) $machines->first()->id);

        $bindings = [
            'paint' => ['resource_type' => 'paint_line', 'workshop_id' => $workshopIds['paint'] ?? null, 'default_line_id' => $lineIds['paint_line'] ?? null],
            'effect' => ['resource_type' => 'effect_line', 'workshop_id' => $workshopIds['effect'] ?? null, 'default_line_id' => $lineIds['effect_line'] ?? null],
            'pack' => ['resource_type' => 'pack_line', 'workshop_id' => $workshopIds['pack'] ?? null, 'default_line_id' => $lineIds['pack_line'] ?? null],
        ];

        foreach ($bindings as $opType => $binding) {
            // 资源缺失时不写入猜测值，让排产明确报「未配置/不存在」，避免制造新的脏关联。
            if ($binding['workshop_id'] === null || $binding['default_line_id'] === null) {
                continue;
            }

            $expectedLine = DB::table('machines')->where('id', $binding['default_line_id'])->first(['id', 'type', 'workshop_id']);
            if (! $expectedLine) {
                continue;
            }

            DB::table('route_ops')
                ->where('op_type', $opType)
                ->orderBy('id')
                ->chunkById(500, function ($ops) use ($binding, $expectedLine) {
                    foreach ($ops as $op) {
                        $currentLine = $op->default_line_id
                            ? DB::table('machines')->where('id', $op->default_line_id)->first(['type', 'workshop_id'])
                            : null;
                        $valid = (int) $op->workshop_id === (int) $binding['workshop_id']
                            && $currentLine
                            && $currentLine->type === $binding['resource_type']
                            && (int) $currentLine->workshop_id === (int) $binding['workshop_id'];

                        if (! $valid) {
                            DB::table('route_ops')->where('id', $op->id)->update([
                                'workshop_id' => $binding['workshop_id'],
                                'default_line_id' => $expectedLine->id,
                            ]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // 无法安全恢复迁移前每条路线各自的错误绑定，回滚只撤销迁移记录。
    }
};
