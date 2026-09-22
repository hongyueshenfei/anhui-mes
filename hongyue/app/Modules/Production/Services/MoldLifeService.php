<?php

namespace App\Modules\Production\Services;

use App\Models\Mold;
use App\Models\MoldLifeLog;
use App\Models\ProdTask;
use Illuminate\Support\Facades\DB;

/**
 * 模具寿命联动服务（C3：报工 → 开模次数累计）
 *
 * 折算：开模次数 = 合格数 ÷ 模穴数（架构 §3.2）；台账只增不改，opened_count 冗余累计可对账。
 */
class MoldLifeService
{
    /**
     * 报工联动：按合格数折算开模次数并累计（冲减可回退，负向留档）
     *
     * @return array{mold_id: ?int, openings: int, mold_code: ?string}
     */
    public function addOpenings(ProdTask $task, int $goodQty): array
    {
        if ($goodQty === 0) {
            return ['mold_id' => null, 'openings' => 0, 'mold_code' => null];
        }

        $mold = Mold::withTrashed()->find($task->mold_id);
        if (! $mold) {
            $mold = $task->product?->defaultMold;
        }
        if (! $mold) {
            return ['mold_id' => null, 'openings' => 0, 'mold_code' => null];
        }

        $cavityCount = max(1, (int) $mold->cavity_count);
        $openings = intdiv((int) $goodQty, $cavityCount); // 每模多穴同时产出；负数=冲减回退

        if ($openings === 0) {
            return ['mold_id' => $mold->id, 'openings' => 0, 'mold_code' => $mold->code];
        }

        DB::transaction(function () use ($mold, $task, $openings) {
            // 流水只增不改（冲减为负向留档）
            MoldLifeLog::create([
                'mold_id' => $mold->id,
                'task_id' => $task->id,
                'opening_count' => $openings,
                'created_at' => now(),
            ]);

            // 冗余累计（可对账 Σmold_life_logs）
            $mold->increment('opened_count', $openings);
        });

        return ['mold_id' => $mold->id, 'openings' => $openings, 'mold_code' => $mold->code];
    }
}