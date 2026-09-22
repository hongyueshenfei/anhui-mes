<?php

namespace App\Modules\Plm\Services;

use App\Models\AuditLog;
use App\Models\Machine;
use App\Models\PlmRoute;
use App\Models\Product;
use App\Models\RouteOp;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 工艺路线服务（版本管理 + 工序模板 + 复制）
 *
 * 工艺路线为独立实体，不归属产品：唯一键 (name, version)。
 */
class RouteService
{
    /**
     * 创建新版本工艺路线（含工序）
     * 请求体：{name, version, remark?, ops: [...]}
     */
    public function createVersion(array $data, ?int $operatorId = null): PlmRoute
    {
        return DB::transaction(function () use ($data) {
            $name = $data['name'];
            $version = $data['version'];

            if (PlmRoute::where('name', $name)->where('version', $version)->exists()) {
                throw new \InvalidArgumentException("工艺路线「{$name}」版本 {$version} 已存在");
            }

            $route = PlmRoute::create([
                'name' => $name,
                'version' => $version,
                'status' => PlmRoute::STATUS_DRAFT,
                'remark' => $data['remark'] ?? null,
            ]);

            if (! empty($data['ops'])) {
                $this->syncOps($route, $data['ops']);
            }

            return $route;
        });
    }

    /**
     * 更新工艺路线（头信息 + 工序）
     *
     * 规则（2026-08-31 业务确认，二次修订）：
     * 工艺路线模块与生产订单**完全脱离关联**：
     * - 路线（含名称/版本/说明/工序）可随时编辑，无状态与引用限制；
     * - 订单/排程在第一次选择路线时已把工序配置快照进 task_schedules
     *   （冗余 op_type/op_name/机台/产线/起止时间），后续路线编辑不影响已生成数据；
     * - 名称+版本唯一。
     */
    public function update(PlmRoute $route, array $data, ?int $operatorId = null): PlmRoute
    {
        return DB::transaction(function () use ($route, $data, $operatorId) {
            $update = [];
            if (array_key_exists('name', $data) && $data['name'] !== $route->name) {
                $update['name'] = $data['name'];
            }
            if (array_key_exists('version', $data) && $data['version'] !== $route->version) {
                $update['version'] = $data['version'];
            }
            if (array_key_exists('remark', $data) && $data['remark'] !== $route->remark) {
                $update['remark'] = $data['remark'];
            }

            if ($update !== []) {
                $name = $update['name'] ?? $route->name;
                $version = $update['version'] ?? $route->version;
                if (PlmRoute::where('name', $name)->where('version', $version)->where('id', '!=', $route->id)->exists()) {
                    throw new \InvalidArgumentException("工艺路线「{$name}」版本 {$version} 已存在");
                }
                $route->update($update);
            }

            if (! empty($data['ops'])) {
                $this->syncOps($route, $data['ops']);
            }

            AuditLog::record(
                user: User::find($operatorId),
                module: 'plm',
                action: 'route_update',
                targetType: 'route',
                targetId: $route->id,
                detail: ['name' => $route->name, 'version' => $route->version],
            );

            return $route->fresh('ops');
        });
    }

    /**
     * 复制工艺路线（验收 A9）
     * 复制源 route 的工序（含产能/换模），生成新名称/版本草稿。
     */
    public function copyFromRoute(PlmRoute $sourceRoute, string $name, string $version, ?string $remark = null, ?int $operatorId = null): PlmRoute
    {
        return DB::transaction(function () use ($sourceRoute, $name, $version, $remark, $operatorId) {
            if (PlmRoute::where('name', $name)->where('version', $version)->exists()) {
                throw new \InvalidArgumentException("工艺路线「{$name}」版本 {$version} 已存在");
            }

            $newRoute = PlmRoute::create([
                'name' => $name,
                'version' => $version,
                'status' => PlmRoute::STATUS_DRAFT,
                'remark' => $remark ?: "复制自「{$sourceRoute->name}」版本{$sourceRoute->version}",
            ]);

            foreach ($sourceRoute->ops as $op) {
                RouteOp::create([
                    'route_id' => $newRoute->id,
                    'seq' => $op->seq,
                    'op_type' => $op->op_type,
                    'op_name' => $op->op_name,
                    'workshop_id' => $op->workshop_id,
                    'mold_change_min' => $op->mold_change_min,
                    'shift_mode' => $op->shift_mode,
                    'machine_type' => $op->machine_type,
                    'rate_key' => $op->rate_key,
                    'default_machine_id' => $op->default_machine_id,
                    'default_line_id' => $op->default_line_id,
                ]);
            }

            AuditLog::record(
                user: User::find($operatorId),
                module: 'plm',
                action: 'route_copy',
                targetType: 'route',
                targetId: $newRoute->id,
                detail: [
                    'source_route_id' => $sourceRoute->id,
                    'source_name' => $sourceRoute->name,
                    'source_version' => $sourceRoute->version,
                    'name' => $name,
                    'version' => $version,
                ],
            );

            return $newRoute->fresh('ops');
        });
    }

    /**
     * 发布工艺路线（草稿→已发布，工程自审）
     */
    public function publish(PlmRoute $route, ?int $operatorId = null): PlmRoute
    {
        if ($route->isPublished()) {
            return $route;
        }

        if ($route->isDisabled()) {
            throw new \InvalidArgumentException('工艺路线已禁用，请先启用后再发布');
        }

        if ($route->ops()->count() === 0) {
            throw new \InvalidArgumentException('工艺路线无工序，无法发布');
        }

        $route->update(['status' => PlmRoute::STATUS_PUBLISHED]);

        AuditLog::record(
            user: User::find($operatorId),
            module: 'plm',
            action: 'publish_route',
            targetType: 'route',
            targetId: $route->id,
            detail: ['name' => $route->name, 'version' => $route->version],
        );

        return $route->fresh();
    }

    /**
     * 下架工艺路线（已发布 → 草稿，退回后可编辑并重新发布）
     */
    public function revoke(PlmRoute $route, ?int $operatorId = null): PlmRoute
    {
        if (! $route->isPublished()) {
            throw new \InvalidArgumentException('仅已发布的工艺可下架');
        }

        $route->update(['status' => PlmRoute::STATUS_DRAFT]);

        AuditLog::record(
            user: User::find($operatorId),
            module: 'plm',
            action: 'route_revoke',
            targetType: 'route',
            targetId: $route->id,
            detail: ['name' => $route->name, 'version' => $route->version],
        );

        return $route->fresh();
    }

    /**
     * 禁用工艺路线（任意状态 → 已禁用；禁用后不可被排产/引用）
     */
    public function disable(PlmRoute $route, ?int $operatorId = null): PlmRoute
    {
        if ($route->isDisabled()) {
            return $route;
        }

        $route->update(['status' => PlmRoute::STATUS_DISABLED]);

        AuditLog::record(
            user: User::find($operatorId),
            module: 'plm',
            action: 'route_disable',
            targetType: 'route',
            targetId: $route->id,
            detail: ['name' => $route->name, 'version' => $route->version],
        );

        return $route->fresh();
    }

    /**
     * 启用工艺路线（已禁用 → 已发布，恢复可用）
     */
    public function enable(PlmRoute $route, ?int $operatorId = null): PlmRoute
    {
        if (! $route->isDisabled()) {
            throw new \InvalidArgumentException('仅已禁用的工艺可启用');
        }

        $route->update(['status' => PlmRoute::STATUS_PUBLISHED]);

        AuditLog::record(
            user: User::find($operatorId),
            module: 'plm',
            action: 'route_enable',
            targetType: 'route',
            targetId: $route->id,
            detail: ['name' => $route->name, 'version' => $route->version],
        );

        return $route->fresh();
    }

    /**
     * 删除工艺路线（任意状态，软删路线头 + 硬删工序）
     *
     * 引用保护：被产品设为默认工艺、或被生产任务锁定的工艺不可删除（409）。
     */
    public function destroy(PlmRoute $route, ?int $operatorId = null): void
    {
        $refs = [];
        $productCount = Product::where('default_route_id', $route->id)->count();
        if ($productCount > 0) {
            $refs[] = "{$productCount} 个产品正引用为默认工艺";
        }
        $taskCount = \App\Models\ProdTask::where('route_id', $route->id)->count();
        if ($taskCount > 0) {
            $refs[] = "{$taskCount} 个生产任务已锁定该工艺版本";
        }
        if ($refs !== []) {
            throw new \InvalidArgumentException('该工艺路线存在引用，无法删除：'.implode('、', $refs).'。请先解除引用');
        }

        DB::transaction(function () use ($route, $operatorId) {
            $route->ops()->delete();
            $route->delete();

            AuditLog::record(
                user: User::find($operatorId),
                module: 'plm',
                action: 'route_delete',
                targetType: 'route',
                targetId: $route->id,
                detail: ['name' => $route->name, 'version' => $route->version],
            );
        });
    }

    /**
     * 同步工序（先清后插）
     *
     * 每个工序保存前按业务规则校验「默认机台/产线」与车间的强对应关系：
     * - 成型类（注塑/吹塑）：必须指定 default_machine_id，且其车间/类型一致、不得同时指定产线
     * - 人工线类（喷漆/效果/包装）：必须指定 default_line_id，且其车间一致、不得同时指定机台
     * - 车间以工序显式指定的 workshop_id 为准（NULL 时按工序类型字典/映射推导）
     *
     * 校验失败抛出 ValidationException（框架渲染为 422）。
     */
    public function syncOps(PlmRoute $route, array $ops): PlmRoute
    {
        $route->ops()->delete();

        foreach ($ops as $index => $op) {
            $this->validateOpResource($op, $index);

            RouteOp::create([
                'route_id' => $route->id,
                'seq' => $op['seq'] ?? ($index + 1),
                'op_type' => $op['op_type'],
                'op_name' => $op['op_name'],
                'workshop_id' => $op['workshop_id'] ?? null,
                'mold_change_min' => $op['mold_change_min'] ?? null,
                'shift_mode' => $op['shift_mode'] ?? null,
                'machine_type' => $op['machine_type'] ?? null,
                'rate_key' => $op['rate_key'] ?? null,
                'default_machine_id' => $op['default_machine_id'] ?? null,
                'default_line_id' => $op['default_line_id'] ?? null,
                'std_hours' => $op['std_hours'] ?? null,
                'owner_id' => $op['owner_id'] ?? null,
                'remark' => $op['remark'] ?? null,
            ]);
        }

        return $route->fresh('ops');
    }

    /**
     * 校验单个工序的默认资源（机台/产线）是否与工序类型及车间强对应。
     *
     * 车间解析优先级：工序显式指定的 workshop_id（车间实体化后从车间列表选择）
     * → op_types 字典 workshop → RouteOp 静态常量映射（存量类型兜底）。
     * 默认机台/产线必须属于该车间，且类型与工序匹配。
     * 失败时抛出 ValidationException::withMessages（字段键形如 ops.{i}.default_machine_id），
     * 由 Laravel 异常处理器渲染为 422 JSON。
     */
    private function validateOpResource(array $op, int $index): void
    {
        $opType = $op['op_type'] ?? null;
        $dict = \App\Models\OpType::where('op_type', $opType)->first();

        $isMolding = $dict ? (bool) $dict->is_molding : in_array($opType, [RouteOp::OP_MOLDING_INJECTION, RouteOp::OP_MOLDING_BLOW], true);

        // 车间解析：显式 workshop_id 优先（须存在），否则字典/映射推导
        $workshopCode = null;
        if (! empty($op['workshop_id'])) {
            $workshopCode = \App\Models\Workshop::find($op['workshop_id'])?->code;
            if (! $workshopCode) {
                throw ValidationException::withMessages([
                    "ops.{$index}.workshop_id" => ['生产车间不存在'],
                ]);
            }
        } else {
            // 标准工序以代码内置映射为准，避免旧字典仍把 effect 指向 paint 时候选错车间。
            $workshopCode = RouteOp::workshopForOpType((string) $opType) ?? $dict?->workshop;
        }

        $expectedType = RouteOp::resourceTypeFor((string) $opType) ?? $dict?->machine_type;
        // 非成型工序机台类型恒为空：按车间推导产线类型（paint→paint_line，effect→effect_line，pack→pack_line）
        if (! $isMolding && $dict && empty($expectedType)) {
            $expectedType = match ($workshopCode) {
                'effect' => Machine::TYPE_EFFECT_LINE,
                'pack' => Machine::TYPE_PACK_LINE,
                default => Machine::TYPE_PAINT_LINE,
            };
        }

        $machineId = $op['default_machine_id'] ?? null;
        $lineId = $op['default_line_id'] ?? null;

        $messages = [];
        $key = "ops.{$index}";

        if ($isMolding) {
            // 成型工序：默认机台可选（需求5：手动选择项），指定时必须校验车间/类型强对应；不得指定产线
            if (! empty($lineId)) {
                $messages["{$key}.default_line_id"] = ["成型工序（{$opType}）不能同时指定产线"];
            } elseif (! empty($machineId)) {
                $machine = Machine::find($machineId);
                if (! $machine) {
                    $messages["{$key}.default_machine_id"] = ["默认机台不存在"];
                } elseif ($machine->workshop?->code !== $workshopCode) {
                    $machineWorkshop = $machine->workshop_name ?? '未分配';
                    $messages["{$key}.default_machine_id"] = [
                        "车间不匹配：工序「{$opType}」仅可在「{$workshopCode}」车间选择机台，当前机台属于「{$machineWorkshop}」车间",
                    ];
                } elseif ($machine->type !== $expectedType) {
                    $messages["{$key}.default_machine_id"] = [
                        "机台类型不匹配：工序「{$opType}」需要「{$expectedType}」类型机台，当前机台类型为「{$machine->type}」",
                    ];
                }
            }
        } else {
            // 人工线工序（喷漆/效果/包装）：默认产线可选（需求5：手动选择项），指定时必须校验车间/类型强对应；不得指定机台
            if (! empty($machineId)) {
                $messages["{$key}.default_machine_id"] = ["人工线工序（{$opType}）不能同时指定机台"];
            } elseif (! empty($lineId)) {
                $line = Machine::find($lineId);
                if (! $line) {
                    $messages["{$key}.default_line_id"] = ["默认产线不存在"];
                } elseif ($line->workshop?->code !== $workshopCode) {
                    $lineWorkshop = $line->workshop_name ?? '未分配';
                    $messages["{$key}.default_line_id"] = [
                        "车间不匹配：工序「{$opType}」仅可在「{$workshopCode}」车间选择产线，当前产线属于「{$lineWorkshop}」车间",
                    ];
                } elseif ($line->type !== $expectedType) {
                    $messages["{$key}.default_line_id"] = [
                        "产线类型不匹配：工序「{$opType}」需要「{$expectedType}」类型产线，当前产线类型为「{$line->type}」",
                    ];
                } elseif (empty($line->workshop_id)) {
                    $messages["{$key}.default_line_id"] = ["产线资源必须归属车间（workshop_id 不能为空）"];
                }
            }
        }

        // 新字段（标准工时/负责人/备注）基础校验
        if (array_key_exists('std_hours', $op) && $op['std_hours'] !== null && (! is_numeric($op['std_hours']) || (float) $op['std_hours'] < 0)) {
            $messages["{$key}.std_hours"] = ['标准工时必须为不小于 0 的数值'];
        }
        if (! empty($op['owner_id']) && ! \App\Models\User::whereKey($op['owner_id'])->exists()) {
            $messages["{$key}.owner_id"] = ['工序负责人不存在'];
        }
        if (isset($op['remark']) && mb_strlen((string) $op['remark']) > 255) {
            $messages["{$key}.remark"] = ['备注不能超过 255 字'];
        }

        if (! empty($messages)) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * 标准工序模板（工序模板选择器）
     *
     * 数据源 = op_types 字典表（is_active=1，按 sort 排序）。
     * 返回结构兼容旧硬编码：含 op_type/op_name/is_molding/machine_type/need_mold，
     * 另加 workshop（适用车间），供前端工艺编排弹窗选机台/产线候选与车间展示。
     */
    public function opTemplates(): array
    {
        return \App\Models\OpType::query()
            ->where('is_active', 1)
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['op_type', 'op_name', 'is_molding', 'machine_type', 'workshop', 'sort'])
            ->map(fn ($t) => [
                'op_type' => $t->op_type,
                'op_name' => $t->op_name,
                'is_molding' => (bool) $t->is_molding,
                'machine_type' => $t->machine_type,
                'need_mold' => (bool) $t->is_molding,
                'workshop' => $t->workshop,
                'sort' => $t->sort,
            ])
            ->all();
    }

    /**
     * 产能预览换算（验收 A9：输入 1000 件即时显示需 X 小时）
     */
    public function previewCapacity(int $qty, ?float $capacityPerHour, ?int $moldChangeMin): array
    {
        return CapacityCalcService::preview($qty, $capacityPerHour, $moldChangeMin);
    }
}
