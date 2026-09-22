<?php

namespace App\Modules\Plm\Services;

use App\Models\AuditLog;
use App\Models\Mold;
use App\Models\MoldLifeLog;
use App\Models\MoldMaintainRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 模具台账服务（档案 CRUD + 寿命核算 + 保养计划 + 三匹配基础）
 */
class MoldService
{
    /**
     * 模具寿命核算：开模次数 = 报工合格数 ÷ 模穴数（向上取整）
     * 架构定义：开模次数 = 报工数 ÷ 模穴数。
     */
    public static function lifeOpeningsForQty(int $goodQty, int $cavityCount): int
    {
        if ($goodQty < 0) {
            throw new \InvalidArgumentException('报工合格数不能为负');
        }
        if ($cavityCount <= 0) {
            throw new \InvalidArgumentException('模穴数必须大于 0');
        }

        return (int) ceil($goodQty / $cavityCount);
    }

    /**
     * 更新模具累计开模次数（由报工事件联动；一期提供函数与日志写入）
     */
    public static function addOpenings(Mold $mold, int $goodQty, ?int $taskId = null, ?int $workReportId = null): Mold
    {
        $openings = self::lifeOpeningsForQty($goodQty, (int) $mold->cavity_count);

        if ($openings <= 0) {
            return $mold;
        }

        return DB::transaction(function () use ($mold, $openings, $taskId, $workReportId) {
            $mold->increment('opened_count', $openings);

            MoldLifeLog::create([
                'mold_id' => $mold->id,
                'task_id' => $taskId,
                'work_report_id' => $workReportId,
                'opening_count' => $openings,
                'created_at' => now(),
            ]);

            return $mold->fresh();
        });
    }

    /**
     * 创建模具
     */
    public function create(array $data): Mold
    {
        return DB::transaction(function () use ($data) {
            $mold = Mold::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
                'status' => $data['status'] ?? Mold::STATUS_IN_USE,
                'location' => $data['location'] ?? null,
                'cavity_count' => $data['cavity_count'] ?? 1,
                'lifetime_openings' => $data['lifetime_openings'] ?? 0,
                'opened_count' => 0,
                'purchase_cost' => $data['purchase_cost'] ?? null,
                'purchase_date' => $data['purchase_date'] ?? null,
                'vendor' => $data['vendor'] ?? null,
                'maintain_cycle_days' => $data['maintain_cycle_days'] ?? null,
                'last_maintain_at' => $data['last_maintain_at'] ?? null,
                'next_maintain_at' => $data['next_maintain_at'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncRelations($mold, $data);

            return $mold->fresh(['products:id,code,name', 'machines:id,code,name']);
        });
    }

    /**
     * 更新模具（含产品/机台关联重建）
     */
    public function update(Mold $mold, array $data): Mold
    {
        return DB::transaction(function () use ($mold, $data) {
            $fill = collect($data)->only([
                'name', 'type', 'status', 'location', 'cavity_count', 'lifetime_openings',
                'purchase_cost', 'purchase_date', 'vendor', 'maintain_cycle_days',
                'last_maintain_at', 'next_maintain_at', 'notes',
            ])->all();

            if (! empty($fill)) {
                $mold->update($fill);
            }

            if (array_key_exists('product_ids', $data) || array_key_exists('machine_ids', $data)) {
                $this->syncRelations($mold, $data);
            }

            AuditLog::record(
                user: User::find($data['operator_id'] ?? null),
                module: 'plm',
                action: 'mold_update',
                targetType: 'mold',
                targetId: $mold->id,
                detail: ['code' => $mold->code, 'fields' => array_keys($fill)],
            );

            return $mold->fresh(['products:id,code,name', 'machines:id,code,name']);
        });
    }

    /**
     * 登记保养/维修记录（验收 A10：保养计划录入，到期提醒）
     * type=maintain 时联动更新 last_maintain_at / next_maintain_at
     */
    public function addMaintainRecord(Mold $mold, array $data): MoldMaintainRecord
    {
        return DB::transaction(function () use ($mold, $data) {
            $record = MoldMaintainRecord::create([
                'mold_id' => $mold->id,
                'type' => $data['type'] ?? MoldMaintainRecord::TYPE_MAINTAIN,
                'at' => $data['at'] ?? now(),
                'cost' => $data['cost'] ?? 0,
                'vendor' => $data['vendor'] ?? null,
                'remark' => $data['remark'] ?? null,
            ]);

            if ($record->type === MoldMaintainRecord::TYPE_MAINTAIN) {
                $next = null;
                if ($mold->maintain_cycle_days) {
                    $next = $record->at->copy()->addDays((int) $mold->maintain_cycle_days);
                }

                $mold->update([
                    'last_maintain_at' => $record->at,
                    'next_maintain_at' => $next,
                ]);
            }

            AuditLog::record(
                user: User::find($data['operator_id'] ?? null),
                module: 'plm',
                action: 'mold_maintain',
                targetType: 'mold',
                targetId: $mold->id,
                detail: ['record_type' => $record->type, 'at' => $record->at->toDateTimeString()],
            );

            return $record;
        });
    }

    /**
     * 模具详情（含寿命/保养提醒/产品/机台）
     */
    public function detail(Mold $mold): array
    {
        $mold->load([
            'products:id,code,name',
            'machines:id,code,name',
            'maintainRecords',
            'lifeLogs' => fn ($q) => $q->limit(20),
        ]);

        $data = $mold->toArray();
        $data['remaining_openings'] = $mold->remaining_openings;
        $data['maintain_due'] = $mold->isMaintainDue();
        $data['life_used_pct'] = $mold->lifetime_openings > 0
            ? round($mold->opened_count / $mold->lifetime_openings * 100, 1)
            : 0;

        return $data;
    }

    /**
     * 同步产品/机台关联
     */
    private function syncRelations(Mold $mold, array $data): void
    {
        if (isset($data['product_ids']) && is_array($data['product_ids'])) {
            $pivot = [];
            foreach ($data['product_ids'] as $productId) {
                $pivot[$productId] = ['is_default' => (int) ((int) $productId === (int) ($data['default_product_id'] ?? 0))];
            }
            $mold->products()->sync($pivot);
        }

        if (isset($data['machine_ids']) && is_array($data['machine_ids'])) {
            $mold->machines()->sync($data['machine_ids']);
        }
    }
}