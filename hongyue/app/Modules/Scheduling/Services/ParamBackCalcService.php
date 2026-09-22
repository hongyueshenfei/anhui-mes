<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Machine;
use App\Models\Mold;
use App\Models\MoldChangeMatrix;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use Illuminate\Support\Carbon;

/**
 * 参数反推服务（APS一期：基础数据准确性治理）
 *
 * 口径：基础数据（换模时间/标准产能）初期不准是常态——系统先按默认值跑起来
 * （矩阵为空自动回落 工序默认>机台默认），待 MES 积累实际报工数据后，
 * 反推生成参数修正建议。**只产建议，不自动落参数**，工程师确认后维护。
 *
 * - 产能反推：有实际起止的工序，实际速率 = Σ报工合格 ÷ 实际时长（小时），
 *   按机台聚合（样本数/均值/当前标准产能/偏差%）
 * - 换模反推：同机台相邻排程换模（前序 actual_end → 后序 actual_start 间隔），
 *   按模具对聚合（0~240 分钟视为有效换模样本，超出视为停产/其他原因不采样），
 *   对照现有矩阵记录给出差异
 */
class ParamBackCalcService
{
    /** 换模有效样本上限（分钟）：超过视为停产/排空，不计入换模统计 */
    private const CHANGEOVER_SAMPLE_MAX_MIN = 240;

    /**
     * 反推建议报告
     *
     * @return array{capacity: list<array>, changeover: list<array>}
     */
    public function report(): array
    {
        return [
            'capacity' => $this->capacitySuggestions(),
            'changeover' => $this->changeoverSuggestions(),
        ];
    }

    /**
     * 产能反推：按机台聚合实际生产速率，对照标准产能
     */
    private function capacitySuggestions(): array
    {
        $rows = TaskSchedule::whereNotNull('actual_start')
            ->whereNotNull('actual_end')
            ->whereNotNull('machine_id')
            ->where('is_interrupted', 0)
            ->get();

        /** @var array<int, list<float>> $samples */
        $samples = [];
        foreach ($rows as $row) {
            $done = (int) WorkReport::where('schedule_id', $row->id)->sum('qty');
            if ($done <= 0) {
                continue; // 无实际产出无法反推
            }
            $seconds = Carbon::parse($row->actual_end)->getTimestamp() - Carbon::parse($row->actual_start)->getTimestamp();
            if ($seconds <= 0) {
                continue;
            }
            $samples[(int) $row->machine_id][] = $done / ($seconds / 3600);
        }

        $result = [];
        foreach ($samples as $machineId => $rates) {
            $machine = Machine::find($machineId);
            if (! $machine) {
                continue;
            }
            $avg = array_sum($rates) / count($rates);
            $current = (float) $machine->capacity_per_hour;
            $result[] = [
                'machine_id' => $machineId,
                'machine_code' => $machine->code,
                'machine_name' => $machine->name,
                'current_capacity' => $current,
                'actual_avg' => round($avg, 1),
                'samples' => count($rates),
                'deviation_pct' => $current > 0 ? round(($avg - $current) / $current * 100, 1) : null,
            ];
        }

        return $result;
    }

    /**
     * 换模反推：同机台相邻排程的模具切换间隔，按模具对聚合
     */
    private function changeoverSuggestions(): array
    {
        $byMachine = TaskSchedule::whereNotNull('mold_id')
            ->whereNotNull('actual_start')
            ->whereNotNull('actual_end')
            ->whereNotNull('machine_id')
            ->where('is_interrupted', 0)
            ->orderBy('actual_start')
            ->get()
            ->groupBy('machine_id');

        /** @var array<string, list<int>> $samples */
        $samples = [];
        foreach ($byMachine as $machineRows) {
            $prev = null;
            foreach ($machineRows as $row) {
                if ($prev !== null && (int) $prev->mold_id !== (int) $row->mold_id) {
                    $gapMin = intdiv(
                        Carbon::parse($row->actual_start)->getTimestamp() - Carbon::parse($prev->actual_end)->getTimestamp(),
                        60,
                    );
                    if ($gapMin >= 0 && $gapMin <= self::CHANGEOVER_SAMPLE_MAX_MIN) {
                        $samples[(int) $prev->mold_id.':'.(int) $row->mold_id][] = $gapMin;
                    }
                }
                $prev = $row;
            }
        }

        $result = [];
        foreach ($samples as $key => $minutes) {
            [$fromId, $toId] = array_map('intval', explode(':', $key));
            $matrix = MoldChangeMatrix::where('from_mold_id', $fromId)->where('to_mold_id', $toId)->first();
            $avg = array_sum($minutes) / count($minutes);
            $result[] = [
                'from_mold_id' => $fromId,
                'to_mold_id' => $toId,
                'from_mold_code' => Mold::find($fromId)?->code,
                'to_mold_code' => Mold::find($toId)?->code,
                'actual_avg_min' => round($avg, 1),
                'samples' => count($minutes),
                'in_matrix' => $matrix !== null,
                'current_matrix_min' => $matrix !== null ? (int) $matrix->changeover_minutes : null,
                'is_forbidden' => $matrix !== null ? (int) $matrix->is_forbidden : 0,
            ];
        }

        return $result;
    }
}
