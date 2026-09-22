<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Models\TaskSchedule;
use App\Models\Workshop;
use App\Modules\Scheduling\Services\ScheduleCapacityRecalculationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 同步产线新产能并重算现有未完工工单排程。
 *
 * 这是一次性数据修正命令，避免只改产线主数据而留下旧的工序计划结束时间。
 */
class SyncLineCapacitySchedules extends Command
{
    protected $signature = 'schedule:sync-line-capacity';

    protected $description = '同步 P-01/P-02/P-03 产能并重算未完工工单排程';

    public function handle(ScheduleCapacityRecalculationService $recalculator): int
    {
        $lines = [
            'P-01' => ['name' => '喷漆线', 'type' => Machine::TYPE_PAINT_LINE, 'workshop' => Workshop::CODE_PAINT, 'capacity' => 1500],
            'P-02' => ['name' => '效果线', 'type' => Machine::TYPE_EFFECT_LINE, 'workshop' => Workshop::CODE_EFFECT, 'capacity' => 1500],
            'P-03' => ['name' => '包装线', 'type' => Machine::TYPE_PACK_LINE, 'workshop' => Workshop::CODE_PACK, 'capacity' => 3000],
        ];

        $summary = DB::transaction(function () use ($lines, $recalculator): array {
            $summary = [];
            $lineIds = [];
            foreach ($lines as $code => $line) {
                $workshop = Workshop::where('code', $line['workshop'])->first();
                if (! $workshop) {
                    throw new \RuntimeException("车间 {$line['workshop']} 不存在");
                }

                $machine = Machine::firstOrNew(['code' => $code]);
                $isNew = ! $machine->exists;
                // 该命令负责修正资源归属和触发排程重算，不得覆盖机台管理中
                // 已维护的产能/班制/状态；固定默认值只用于首次补建缺失产线。
                $machine->fill([
                    'name' => $machine->name ?: $line['name'],
                    'type' => $line['type'],
                    'workshop_id' => $workshop->id,
                ]);
                if ($isNew || $machine->capacity_per_hour === null) {
                    $machine->capacity_per_hour = $line['capacity'];
                }
                if ($isNew) {
                    $machine->fill([
                        'mold_change_min' => 0,
                        'shift_mode' => 'day',
                        'status' => Machine::STATUS_RUNNING,
                        'is_active' => 1,
                    ]);
                }
                $machine->save();
                $lineIds[$code] = $machine->id;
            }

            $resourceMap = [
                'paint' => $lineIds['P-01'],
                'effect' => $lineIds['P-02'],
                'pack' => $lineIds['P-03'],
            ];
            $reassignedByLine = ['P-01' => 0, 'P-02' => 0, 'P-03' => 0];
            foreach ($resourceMap as $opType => $machineId) {
                $lineCode = match ($opType) {
                    'paint' => 'P-01',
                    'effect' => 'P-02',
                    'pack' => 'P-03',
                };
                $reassignedByLine[$lineCode] += TaskSchedule::query()
                    ->where('op_type', $opType)
                    ->where(function ($query) use ($machineId): void {
                        $query->whereNull('machine_id')->orWhere('machine_id', '!=', $machineId);
                    })
                    ->update(['machine_id' => $machineId, 'is_overlap' => 0]);
            }

            foreach ($lines as $code => $line) {
                $machine = Machine::where('code', $code)->firstOrFail();
                $result = $recalculator->recalculateForMachine($machine->fresh());
                $summary[] = [$code, $line['capacity'], $result['task_count'], $result['changed_count'], $reassignedByLine[$code]];
            }

            // 产线按当前确定的最小资源集收敛：喷漆/效果/包装各保留一条标准线。
            // 有排程引用的遗留资源不直接删除，先保留并交由计划员重排；无引用的多余效果线安全停用。
            $effectWorkshopId = Workshop::where('code', Workshop::CODE_EFFECT)->value('id');
            if ($effectWorkshopId) {
                Machine::query()
                    ->where('workshop_id', $effectWorkshopId)
                    ->where('is_active', 1)
                    ->whereNotIn('code', array_keys($lines))
                    ->whereDoesntHave('schedules')
                    ->update(['is_active' => 0, 'status' => Machine::STATUS_STOPPED]);
            }

            return $summary;
        });

        $this->table(
            ['产线', '新产能(件/h)', '关联未完工工单', '更新排程行', '资源迁移行'],
            $summary,
        );
        $this->info('产线产能与未完工工单排程同步完成');

        return self::SUCCESS;
    }
}
