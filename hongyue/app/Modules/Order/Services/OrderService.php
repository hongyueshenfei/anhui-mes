<?php

namespace App\Modules\Order\Services;

use App\Models\Bom;
use App\Models\Machine;
use App\Models\Material;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\PlmRoute;
use App\Models\Product;
use App\Models\ProdTask;
use App\Models\RouteOp;
use App\Models\TaskSchedule;
use App\Models\User;
use App\Models\WorkOrderLog;
use App\Models\WorkReport;
use App\Models\Workshop;
use Illuminate\Support\Facades\DB;

/**
 * 订单服务（手工建单 + Excel 导入落库）
 *
 * - 建单即按明细行生成生产任务（prod_tasks，产品级排产对象）
 * - 订单/明细状态由任务状态聚合（syncStatus）
 */
class OrderService
{
    /** 单号前缀：manual→SO / excel→XE / k3→K3 */
    private const NO_PREFIX = [
        Order::SRC_MANUAL => 'SO',
        Order::SRC_EXCEL => 'XE',
        Order::SRC_K3 => 'K3',
        Order::SRC_MINI_PROGRAM => 'MP',
    ];

    /**
     * 手工建单（含多产品明细）
     *
     * @param  array  $data  ['customer_name'=>..., 'delivery_date'=>..., 'priority'=>..., 'remark'=>..., 'lines'=>[['product_code'=>..., 'qty'=>..., 'delivery_date'=>..., 'priority'=>..., 'mold_id'=>...]]]
     */
    public function createOrder(array $data, ?int $userId = null): Order
    {
        $order = DB::transaction(function () use ($data, $userId) {
            $prefix = self::NO_PREFIX[Order::SRC_MANUAL];

            $order = Order::create([
                'order_no' => $this->generateOrderNo($prefix),
                'src' => Order::SRC_MANUAL,
                'customer_name' => $data['customer_name'] ?? '',
                // 创建默认进入「工艺确认」：配置并确认工艺路线后才能排产
                'status' => Order::STATUS_ROUTE_CONFIRM,
                'priority' => (int) ($data['priority'] ?? 3),
                'delivery_date' => $data['delivery_date'] ?? null,
                'remark' => $data['remark'] ?? null,
                'created_by' => $userId,
            ]);

            $idx = 0;
            foreach ($data['lines'] as $line) {
                $product = Product::where('code', $line['product_code'])->firstOrFail();
                $orderLine = OrderLine::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'qty' => (int) $line['qty'],
                    'delivery_date' => $line['delivery_date'] ?? ($data['delivery_date'] ?? null),
                    'priority' => (int) ($line['priority'] ?? $data['priority'] ?? 3),
                    'status' => OrderLine::STATUS_PENDING,
                    'done_qty' => 0,
                    'mold_id' => $line['mold_id'] ?? null,
                    'line_no' => ++$idx,
                ]);

                ProdTask::create([
                    'task_no' => $this->generateTaskNo($order->order_no, $idx),
                    'order_line_id' => $orderLine->id,
                    'product_id' => $product->id,
                    'route_id' => $product->default_route_id,
                    'mold_id' => $line['mold_id'] ?? $product->default_mold_id,
                    'qty' => (int) $line['qty'],
                    'done_qty' => 0,
                    'status' => ProdTask::STATUS_UNSCHEDULED,
                    'priority' => (int) ($line['priority'] ?? $data['priority'] ?? 3),
                    'delivery_date' => $line['delivery_date'] ?? ($data['delivery_date'] ?? null),
                    'deviation_pct' => 0,
                    'is_locked' => 0,
                    'source' => Order::SRC_MANUAL,
                ]);
            }

            return $order;
        });

        return $order->load('lines.product', 'lines.tasks');
    }

    /**
     * 将一个尚未开工的生产任务拆成多个可独立排产的子任务。
     *
     * 父任务保留原始数量与任务号，仅作为拆单容器；子任务才进入任务池、
     * 甘特、报工和入库链路。拆单阶段只拆分数量，不绑定模具或机台；资源
     * 在甘特图落位时再按产品、模具和机台适配关系选择并校验。
     *
     * @param  array<int, array{qty:int}>  $parts
     * @return array{parent_task_id:int,parent_task_no:string,task_ids:list<int>,tasks:\Illuminate\Support\Collection,remaining_qty:int,split_total:int}
     */
    public function splitTask(ProdTask $task, array $parts, ?int $userId = null): array
    {
        $parentTaskId = 0;
        $children = collect();

        DB::transaction(function () use ($task, $parts, $userId, &$parentTaskId, &$children): void {
            /** @var ProdTask $parent */
            $parent = ProdTask::withoutGlobalScope('leaf_task')
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();
            $parent->loadMissing('product');
            $order = $parent->line()->with('order')->first()?->order;

            if (! $order) {
                throw new \InvalidArgumentException('任务所属订单不存在，无法拆单');
            }
            if (! in_array($order->status, [Order::STATUS_ROUTE_CONFIRM, Order::STATUS_PENDING], true)) {
                throw new \InvalidArgumentException('只有工艺确认中或待排产工单可以拆单');
            }
            if ((int) $parent->is_split_parent === 1) {
                throw new \InvalidArgumentException('该任务已经拆单，请在子任务上分别排产');
            }
            if ($parent->parent_task_id !== null) {
                throw new \InvalidArgumentException('该任务已经是拆单子任务，不可再次拆分');
            }
            if ($parent->status !== ProdTask::STATUS_UNSCHEDULED || (int) $parent->is_locked === 1) {
                throw new \InvalidArgumentException('只有未排产且未锁定的任务可以拆单');
            }
            if ((int) $parent->done_qty > 0 || (int) $parent->stocked_qty > 0
                || $parent->schedules()->exists() || $parent->reports()->exists() || $parent->stockIns()->exists()) {
                throw new \InvalidArgumentException('任务已有排程、报工或入库记录，不可拆单');
            }

            $normalized = collect($parts)->map(function (array $part, int $index): array {
                $qty = (int) ($part['qty'] ?? 0);
                if ($qty <= 0) {
                    throw new \InvalidArgumentException('第 '.($index + 1).' 个子任务数量必须大于 0');
                }

                return ['qty' => $qty];
            })->values();

            if ($normalized->count() < 2 || $normalized->count() > 20) {
                throw new \InvalidArgumentException('拆单至少需要 2 个子任务，最多 20 个');
            }
            $sum = (int) $normalized->sum('qty');
            if ($sum !== (int) $parent->qty) {
                throw new \InvalidArgumentException("子任务数量合计必须等于原任务数量（{$parent->qty}）");
            }

            $parent->update([
                'status' => ProdTask::STATUS_SPLIT,
                'is_split_parent' => 1,
                'is_locked' => 0,
            ]);

            $parentTaskId = (int) $parent->id;
            $splitTotal = $normalized->count();
            foreach ($normalized as $index => $part) {
                $suffix = '-S'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                $baseNo = substr((string) $parent->task_no, 0, 32 - strlen($suffix));
                $child = ProdTask::create([
                    'parent_task_id' => $parent->id,
                    'task_no' => $baseNo.$suffix,
                    'order_line_id' => $parent->order_line_id,
                    'product_id' => $parent->product_id,
                    'route_id' => $parent->route_id,
                    'route_ops_override' => $parent->route_ops_override,
                    // 拆单只拆数量；模具在甘特图排产时再选择和校验。
                    'mold_id' => null,
                    'qty' => $part['qty'],
                    'done_qty' => 0,
                    'stocked_qty' => 0,
                    'status' => ProdTask::STATUS_UNSCHEDULED,
                    'priority' => $parent->priority,
                    'delivery_date' => $parent->delivery_date,
                    'deviation_pct' => 0,
                    'is_locked' => 0,
                    'source' => $parent->source,
                    'split_index' => $index + 1,
                    'split_total' => $splitTotal,
                    'is_split_parent' => 0,
                ]);
                $children->push($child);
            }

            $this->logAction(
                $order,
                WorkOrderLog::ACTION_SPLIT,
                $order->status,
                $order->status,
                $userId,
                "任务 {$parent->task_no} 拆分为 {$splitTotal} 个子任务",
                [
                    'parent_task_id' => $parent->id,
                    'parent_task_no' => $parent->task_no,
                    'parts' => $normalized->all(),
                    'child_task_ids' => $children->pluck('id')->all(),
                ],
            );
        });

        $childIds = $children->pluck('id')->map(fn ($id) => (int) $id)->values();
        $children = ProdTask::whereIn('id', $childIds->all())->with(['product', 'mold'])->get();

        return [
            'parent_task_id' => $parentTaskId,
            'parent_task_no' => (string) $task->task_no,
            'task_ids' => $childIds->all(),
            'tasks' => $children,
            'remaining_qty' => 0,
            'split_total' => $children->count(),
        ];
    }

    /**
     * 由已校验的行数据批量落库（Excel 导入 / 四期 K3 拉单复用）
     *
     * @param  array  $rows  [['product_code'=>..., 'qty'=>..., 'delivery_date'=>..., 'priority'=>..., 'customer_name'=>...]]
     */
    public function createOrderFromRows(array $rows, string $src, ?int $userId = null): Order
    {
        if ($rows === []) {
            throw new \InvalidArgumentException('导入明细为空');
        }

        $order = DB::transaction(function () use ($rows, $src, $userId) {
            $prefix = self::NO_PREFIX[$src] ?? 'XE';

            $order = Order::create([
                'order_no' => $this->generateOrderNo($prefix),
                'src' => $src,
                'customer_name' => $rows[0]['customer_name'] ?? '',
                // 创建默认进入「工艺确认」（All 建单入口一致）
                'status' => Order::STATUS_ROUTE_CONFIRM,
                'priority' => (int) ($rows[0]['priority'] ?? 3),
                'delivery_date' => $rows[0]['delivery_date'] ?? null,
                'remark' => 'Excel 导入',
                'created_by' => $userId,
            ]);

            $idx = 0;
            foreach ($rows as $row) {
                $product = Product::where('code', $row['product_code'])->firstOrFail();
                $orderLine = OrderLine::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'qty' => (int) $row['qty'],
                    'delivery_date' => $row['delivery_date'] ?? $order->delivery_date,
                    'priority' => (int) ($row['priority'] ?? $order->priority),
                    'status' => OrderLine::STATUS_PENDING,
                    'done_qty' => 0,
                    'mold_id' => $row['mold_id'] ?? $product->default_mold_id,
                    'line_no' => ++$idx,
                ]);

                ProdTask::create([
                    'task_no' => $this->generateTaskNo($order->order_no, $idx),
                    'order_line_id' => $orderLine->id,
                    'product_id' => $product->id,
                    'route_id' => $product->default_route_id,
                    'mold_id' => $row['mold_id'] ?? $product->default_mold_id,
                    'qty' => (int) $row['qty'],
                    'done_qty' => 0,
                    'status' => ProdTask::STATUS_UNSCHEDULED,
                    'priority' => (int) ($row['priority'] ?? $order->priority),
                    'delivery_date' => $row['delivery_date'] ?? $order->delivery_date,
                    'deviation_pct' => 0,
                    'is_locked' => 0,
                    'source' => $src === Order::SRC_EXCEL ? Order::SRC_EXCEL : Order::SRC_MANUAL,
                ]);
            }

            return $order;
        });

        return $order->load('lines.product', 'lines.tasks');
    }

    /**
     * 由金蝶拉单数据落库（四期 K1）—— 幂等由调用方按 k3_bill_no 查重
     *
     * @param  array  $rows  [['product_code'=>..., 'qty'=>..., 'delivery_date'=>..., 'priority'=>...]]
     */
    public function createOrderFromK3(array $rows, string $billNo, string $customerName = '', ?int $userId = null): Order
    {
        if ($rows === []) {
            throw new \InvalidArgumentException('金蝶拉单明细为空');
        }

        $order = DB::transaction(function () use ($rows, $billNo, $customerName, $userId) {
            $order = Order::create([
                'order_no' => 'K3-'.$billNo,
                'src' => Order::SRC_K3,
                'k3_bill_no' => $billNo,
                'customer_name' => $customerName ?: '金蝶同步客户',
                // 创建默认进入「工艺确认」（All 建单入口一致）
                'status' => Order::STATUS_ROUTE_CONFIRM,
                'priority' => (int) ($rows[0]['priority'] ?? 3),
                'delivery_date' => $rows[0]['delivery_date'] ?? null,
                'remark' => '来自金蝶生产订单（四期拉单）',
                'created_by' => $userId,
            ]);

            $idx = 0;
            foreach ($rows as $row) {
                $product = Product::where('code', $row['product_code'])->firstOrFail();
                $orderLine = OrderLine::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'qty' => (int) $row['qty'],
                    'delivery_date' => $row['delivery_date'] ?? $order->delivery_date,
                    'priority' => (int) ($row['priority'] ?? $order->priority),
                    'status' => OrderLine::STATUS_PENDING,
                    'done_qty' => 0,
                    'mold_id' => $row['mold_id'] ?? $product->default_mold_id,
                    'line_no' => ++$idx,
                ]);

                ProdTask::create([
                    'task_no' => $this->generateTaskNo($order->order_no, $idx),
                    'order_line_id' => $orderLine->id,
                    'product_id' => $product->id,
                    'route_id' => $product->default_route_id,
                    'mold_id' => $row['mold_id'] ?? $product->default_mold_id,
                    'qty' => (int) $row['qty'],
                    'done_qty' => 0,
                    'status' => ProdTask::STATUS_UNSCHEDULED,
                    'priority' => (int) ($row['priority'] ?? $order->priority),
                    'delivery_date' => $row['delivery_date'] ?? $order->delivery_date,
                    'deviation_pct' => 0,
                    'is_locked' => 0,
                    'source' => Order::SRC_K3,
                ]);
            }

            return $order;
        });

        return $order->load('lines.product', 'lines.tasks');
    }

    /**
     * 同步订单/明细状态（任务状态聚合，排产/报工后调用）
     */
    public function syncStatus(Order $order): void
    {
        // 人工生命周期状态（停工/中止/关闭）不随任务聚合自动流转
        if (in_array($order->status, [Order::STATUS_PAUSED, Order::STATUS_ABORTED, Order::STATUS_CLOSED], true)) {
            return;
        }

        $lines = $order->lines()->with('tasks')->get();
        $lineStatuses = [];

        foreach ($lines as $line) {
            $statuses = $line->tasks->pluck('status');
            // 订单行完成量是任务末工序完成量的派生汇总，不再只同步状态。
            // 多任务订单行按任务完成量求和，并限制在订单行数量以内，避免
            // 中间工序报工或超报把订单行显示成超过订货量。
            $doneQty = min(
                (int) $line->qty,
                max(0, (int) $line->tasks->sum(fn (ProdTask $task) => (int) $task->done_qty)),
            );
            if ($statuses->isEmpty()) {
                $lineStatuses[$line->id] = OrderLine::STATUS_PENDING;
            } elseif ($statuses->every(fn ($s) => $s === ProdTask::STATUS_DONE)) {
                $lineStatuses[$line->id] = OrderLine::STATUS_DONE;
            } elseif ($statuses->contains(fn ($s) => in_array($s, [ProdTask::STATUS_IN_PROGRESS, ProdTask::STATUS_PAUSED]))) {
                $lineStatuses[$line->id] = OrderLine::STATUS_IN_PROGRESS;
            } elseif ($statuses->contains(ProdTask::STATUS_SCHEDULED)) {
                $lineStatuses[$line->id] = OrderLine::STATUS_SCHEDULED;
            } else {
                $lineStatuses[$line->id] = OrderLine::STATUS_PENDING;
            }

            $line->setAttribute('_derived_done_qty', $doneQty);
        }

        foreach ($lineStatuses as $lineId => $status) {
            $line = $lines->firstWhere('id', $lineId);
            OrderLine::whereKey($lineId)->update([
                'status' => $status,
                'done_qty' => (int) ($line?->getAttribute('_derived_done_qty') ?? 0),
            ]);
        }

        // 订单状态 = 明细状态聚合
        $orderStatus = match (true) {
            $lineStatuses === [] => Order::STATUS_PENDING,
            collect($lineStatuses)->every(fn ($s) => $s === OrderLine::STATUS_DONE) => Order::STATUS_PRODUCED,
            collect($lineStatuses)->contains(OrderLine::STATUS_IN_PROGRESS) => Order::STATUS_IN_PROGRESS,
            collect($lineStatuses)->contains(OrderLine::STATUS_SCHEDULED) => Order::STATUS_SCHEDULED,
            // 未排产未生产：保持现状（route_confirm 工艺确认中 / pending 工艺已确认待排产）
            // 例外：订单仍标记已排产但任务已全部回退待排（如排产版本回滚）→ 回退待排产
            default => in_array($order->status, [Order::STATUS_SCHEDULED])
                ? Order::STATUS_PENDING
                : $order->status,
        };

        $orderUpdate = ['status' => $orderStatus];
        if ($orderStatus === Order::STATUS_PRODUCED) {
            // 报工驱动进入已入库时补齐完结时间，避免状态已完成但时间为空。
            $orderUpdate['completed_at'] = $order->completed_at ?? now();
        } elseif ($order->status === Order::STATUS_PRODUCED || $order->completed_at !== null) {
            // 末工序冲减后订单必须回到生产态，同时清除过期的完结时间。
            $orderUpdate['completed_at'] = null;
        }
        $order->update($orderUpdate);
    }

    /**
     * 生成业务单号：SO-20260831-0231（前缀-日期-4位纯数字，无字母；防并发冲突，唯一约束兜底）
     */
    public function generateOrderNo(string $prefix): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $no = sprintf('%s-%s-%04d', $prefix, now()->format('Ymd'), random_int(0, 9999));
            if (! Order::withTrashed()->where('order_no', $no)->exists()) {
                return $no;
            }
        }

        return sprintf('%s-%s-%08d', $prefix, now()->format('Ymd'), random_int(0, 99999999));
    }

    /**
     * 生成任务号：TSK-<订单号>-<序号>
     */
    public function generateTaskNo(string $orderNo, int $seq): string
    {
        return sprintf('TSK-%s-%02d', $orderNo, $seq);
    }

    /**
     * 记录工单操作日志（统一留痕）
     */
    private function logAction(Order $order, string $action, ?string $fromStatus, ?string $toStatus, ?int $userId, ?string $remark = null, ?array $meta = null): void
    {
        $operatorName = $userId ? \App\Models\User::whereKey($userId)->value('name') : null;

        WorkOrderLog::create([
            'order_id' => $order->id,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'operator_id' => $userId,
            'operator_name' => $operatorName,
            'remark' => $remark,
            'meta' => $meta,
        ]);
    }

    /**
     * 编辑工单头信息（已入库不可编辑）
     */
    public function updateOrder(Order $order, array $data, ?int $userId = null): Order
    {
        if ($order->status === Order::STATUS_PRODUCED) {
            throw new \InvalidArgumentException('工单已入库，不可再编辑');
        }

        $allowed = ['customer_name', 'priority', 'delivery_date', 'remark'];
        $update = collect($data)->only($allowed)->filter(fn ($v) => $v !== null)->all();

        if ($update === []) {
            return $order;
        }

        $from = $order->status;
        $order->update($update);
        $this->logAction($order, WorkOrderLog::ACTION_EDIT, $from, $order->status, $userId, '编辑工单信息');

        return $order->fresh();
    }

    /**
     * 配置/调整任务工艺路线（仅 route_confirm 工艺确认阶段可改；确认进入 pending 后锁定）
     *
     * @param  array  $tasks  [['task_id' => int, 'route_id' => int], ...]
     * @return Order
     */
    public function updateTaskRoutes(Order $order, array $tasks, ?int $userId = null): Order
    {
        if ($order->status !== Order::STATUS_ROUTE_CONFIRM) {
            throw new \InvalidArgumentException('工艺已确认锁定，进入待排产后不可调整工艺路线；如需变更请联系管理员撤销');
        }

        $changes = [];
        DB::transaction(function () use ($order, $tasks, $userId, &$changes) {
            foreach ($tasks as $item) {
                // 一个工单可以包含多个产品任务，必须按整组订单行匹配，不能
                // 将 Collection 作为单值条件传给 where。
                $task = ProdTask::whereIn('order_line_id', $order->lines()->pluck('id')->all())
                    ->where('id', (int) ($item['task_id'] ?? 0))
                    ->first();
                if (! $task) {
                    throw new \InvalidArgumentException('任务不存在或不属于该工单');
                }

                $routeId = (int) ($item['route_id'] ?? 0);
                $route = $routeId ? PlmRoute::find($routeId) : null;
                if (! $route) {
                    throw new \InvalidArgumentException("任务 {$task->task_no} 工艺路线不存在");
                }
                if ($route->status !== PlmRoute::STATUS_PUBLISHED) {
                    throw new \InvalidArgumentException("任务 {$task->task_no} 工艺路线「{$route->version}」未发布，不能配置");
                }

                $oldRouteId = $task->route_id;
                if ($oldRouteId === $routeId) {
                    continue;
                }
                $task->update(['route_id' => $routeId]);
                $changes[] = ['task_id' => $task->id, 'task_no' => $task->task_no, 'route_from' => $oldRouteId, 'route_to' => $routeId];
            }
        });

        if ($changes !== []) {
            $this->logAction($order, WorkOrderLog::ACTION_EDIT, $order->status, $order->status, $userId, '调整任务工艺路线', ['route_changes' => $changes]);
        }

        return $order->fresh();
    }

    /**
     * 订单级工艺明细（GET /orders/{id}/route-ops）
     *
     * 每个任务的工序来源：优先订单级快照（route_ops_override），否则工艺路线库。
     * ops 行统一形状：seq/op_type/op_name/workshop_id(+name)/machine_id(+name)/line_id(+name)/
     * std_hours/owner_id(+name)/remark；成型工序 machine_id 有值、人工线工序 line_id 有值，互斥。
     */
    public function taskRouteOps(Order $order): array
    {
        // 工艺已确认（pending 起）即锁定：仅 route_confirm 可编辑
        $editable = $order->status === Order::STATUS_ROUTE_CONFIRM;

        $taskIds = $order->lines()->pluck('id')->all();
        $tasks = ProdTask::whereIn('order_line_id', $taskIds)
            ->with('product:id,code,name', 'route:id,name,version', 'schedules.machine:id,code,name')
            ->orderBy('id')
            ->get();

        $tasksData = $tasks->map(function (ProdTask $task) {
            // 排程按工序 seq 分组（同一工序可拆多段排到多台机台/产线 → 机台产线多选展示）
            $schedBySeq = $task->schedules->groupBy('seq');
            // 报工净额按排程段聚合（工序完工数量 = 该工序各段报工净额之和）
            $netBySchedule = \App\Models\WorkReport::query()
                ->whereIn('schedule_id', $task->schedules->pluck('id')->all())
                // qty 已按正负直接记录正常报工/冲减，不能再次乘 sign。
                ->selectRaw('schedule_id, SUM(qty) as net')
                ->groupBy('schedule_id')
                ->pluck('net', 'schedule_id')
                ->map(fn ($v) => (int) $v);

            return [
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'product_id' => $task->product_id,
                'product_name' => $task->product?->name,
                'qty' => (int) $task->qty,
                'route_id' => $task->route_id,
                'route_name' => $task->route?->name ?? '未配置工艺路线',
                'route_version' => $task->route?->version,
                'overridden' => $task->hasRouteOpsOverride(),
                'ops' => collect($task->effectiveOps())
                    ->map(fn (array $op) => $this->decorateOp($op))
                    ->map(fn (array $op) => array_merge($op, [
                        // 工序数量：订单级定制值优先，未定制（路线库）取任务数量
                        'qty' => ($op['qty'] ?? null) !== null ? (int) $op['qty'] : (int) $task->qty,
                    ] + $this->scheduleSummaryForOp($op, $schedBySeq, $netBySchedule, (int) $task->qty)))
                    ->values()
                    ->all(),
            ];
        })->all();

        return [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'status' => $order->status,
            'editable' => $editable,
            'tasks' => $tasksData,
        ];
    }

    /**
     * 工序排产/完工汇总（只读信息，来自排程与报工；确认工艺阶段不可修改）
     *
     * - plan_start / plan_end：该工序各排程段的最早开始 / 最晚结束
     * - machines：排程机台/产线（同工序多段多台 → 数组）
     * - done_qty / percent：该工序各段报工净额之和 / 完成进度（对工序数量）
     */
    private function scheduleSummaryForOp(array $op, \Illuminate\Support\Collection $schedBySeq, \Illuminate\Support\Collection $netBySchedule, int $taskQty): array
    {
        $segs = $schedBySeq->get((int) ($op['seq'] ?? 0), collect());
        if ($segs->isEmpty()) {
            return ['plan_start' => null, 'plan_end' => null, 'machines' => null, 'done_qty' => 0, 'percent' => null];
        }

        $start = $segs->min(fn ($s) => $s->plan_start);
        $end = $segs->max(fn ($s) => $s->plan_end);
        $machines = $segs
            ->map(fn ($s) => $s->machine ? trim($s->machine->code.' '.$s->machine->name) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $doneQty = max(0, (int) $segs->sum(fn ($s) => (int) ($netBySchedule->get($s->id) ?? 0)));
        // 工序数量：订单级定制值优先，未定制（路线库工序无 qty）用任务数量
        $planQty = ($op['qty'] ?? null) !== null ? (int) $op['qty'] : $taskQty;

        return [
            'plan_start' => $start ? $start->format('Y-m-d H:i') : null,
            'plan_end' => $end ? $end->format('Y-m-d H:i') : null,
            'machines' => $machines,
            'done_qty' => $doneQty,
            'percent' => $planQty > 0 ? round(min(100, $doneQty / $planQty * 100), 1) : 0.0,
        ];
    }

    /**
     * 编辑订单级工艺明细（PUT /orders/{id}/route-ops）
     *
     * - 仅 route_confirm 工艺确认阶段可编辑（确认进入 pending 后锁定）
     * - 逐任务校验（任务归属订单、可选换路线、逐工序校验），失败抛 InvalidArgumentException
     * - 按数组顺序强制重排 seq = 1..n，快照写入 prod_tasks.route_ops_override
     * - 只对当前工单生效，不回写工艺路线库
     */
    public function updateTaskRouteOps(Order $order, array $payload, ?int $userId = null): array
    {
        if ($order->status !== Order::STATUS_ROUTE_CONFIRM) {
            throw new \InvalidArgumentException('工艺已确认锁定，进入待排产后不可编辑工艺明细；如需变更请联系管理员撤销');
        }

        $lineIds = $order->lines()->pluck('id')->all();
        $logs = [];

        DB::transaction(function () use ($order, $payload, $userId, $lineIds, &$logs) {
            foreach ($payload['tasks'] as $item) {
                // 一个工单可以包含多个产品行；这里必须按整组订单行匹配，
                // 否则多产品工单保存工序快照时会把数组当成单值条件。
                $task = ProdTask::whereIn('order_line_id', $lineIds)
                    ->where('id', (int) ($item['task_id'] ?? 0))
                    ->first();
                if (! $task) {
                    throw new \InvalidArgumentException('任务不存在或不属于该工单');
                }

                // 可选：同批更换工艺路线（沿用 updateTaskRoutes 的校验规则）
                if (! empty($item['route_id'])) {
                    $route = PlmRoute::find((int) $item['route_id']);
                    if (! $route) {
                        throw new \InvalidArgumentException("任务 {$task->task_no} 工艺路线不存在");
                    }
                    if ($route->status !== PlmRoute::STATUS_PUBLISHED) {
                        throw new \InvalidArgumentException("任务 {$task->task_no} 工艺路线「{$route->version}」未发布，不能配置");
                    }
                    $task->route_id = $route->id;
                }

                // 恢复标准工艺路线：清空订单级快照，回退到工艺路线库
                if (! empty($item['restore_standard'])) {
                    $oldCount = $task->hasRouteOpsOverride()
                        ? count($task->route_ops_override)
                        : ($task->route ? $task->route->ops()->count() : 0);
                    $task->route_ops_override = null;
                    $task->save();
                    $logs[] = "恢复标准工艺路线：{$task->task_no}（清除 {$oldCount} 道工序定制）";
                    continue;
                }

                $ops = $item['ops'] ?? [];
                if ($ops === []) {
                    throw new \InvalidArgumentException("任务 {$task->task_no} 工序明细不能为空");
                }

                $normalized = [];
                foreach ($ops as $index => $op) {
                    $normalized[] = $this->validateTaskOp($op, $task, $index);
                }
                // 按数组顺序强制重排 seq = 1..n
                foreach ($normalized as $i => &$op) {
                    $op['seq'] = $i + 1;
                }
                unset($op);

                // 旧工序数：已定制过取快照行数，否则取工艺路线库行数
                $oldCount = $task->hasRouteOpsOverride()
                    ? count($task->route_ops_override)
                    : ($task->route ? $task->route->ops()->count() : 0);

                $task->route_ops_override = $normalized;
                $task->save();

                $logs[] = "编辑工序明细：{$task->task_no} 由 {$oldCount} 道调整为 ".count($normalized).' 道';
            }
        });

        foreach ($logs as $remark) {
            $this->logAction($order, WorkOrderLog::ACTION_EDIT, $order->status, $order->status, $userId, $remark);
        }

        return $this->taskRouteOps($order->fresh());
    }

    /**
     * 校验并规整单道工序（订单级工艺明细）
     *
     * 失败消息直接面向用户，形如「任务 T-xxx 第 N 道工序：...」。
     */
    private function validateTaskOp(array $op, ProdTask $task, int $index): array
    {
        $prefix = "任务 {$task->task_no} 第 ".($index + 1).' 道工序';

        $opType = $op['op_type'] ?? null;
        $dict = $opType ? \App\Models\OpType::where('op_type', $opType)->where('is_active', 1)->first() : null;
        if (! $dict && ! ($opType && array_key_exists($opType, RouteOp::OP_LABELS))) {
            throw new \InvalidArgumentException("{$prefix}：工序类型(op_type)非法或未启用");
        }
        if (! $opType) {
            throw new \InvalidArgumentException("{$prefix}：工序类型(op_type)不能为空");
        }

        $opName = trim((string) ($op['op_name'] ?? ''));
        if ($opName === '') {
            throw new \InvalidArgumentException("{$prefix}：工序名称(op_name)不能为空");
        }
        if (mb_strlen($opName) > 64) {
            throw new \InvalidArgumentException("{$prefix}：工序名称不能超过 64 字");
        }

        $workshopId = (int) ($op['workshop_id'] ?? 0);
        $workshop = $workshopId ? Workshop::find($workshopId) : null;
        if (! $workshop) {
            throw new \InvalidArgumentException("{$prefix}：生产车间(workshop_id)不存在");
        }

        $isMolding = $dict ? (bool) $dict->is_molding : in_array($opType, [RouteOp::OP_MOLDING_INJECTION, RouteOp::OP_MOLDING_BLOW], true);
        // 标准工序以代码内置资源映射为准，避免旧字典把效果工序误判为喷漆产线。
        $expectedMachineType = RouteOp::resourceTypeFor($opType) ?? $dict?->machine_type;

        $machineId = null;
        $lineId = null;

        if ($isMolding) {
            // 成型工序：设备可在排产阶段选择（工艺确认阶段允许为空）；若已指定则须存在、类型匹配（injection/blow）、车间一致；不得指定产线
            if (! empty($op['line_id'])) {
                throw new \InvalidArgumentException("{$prefix}：成型工序不能同时指定产线");
            }
            $machineId = (int) ($op['machine_id'] ?? 0);
            if ($machineId) {
                $machine = Machine::find($machineId);
                if (! $machine) {
                    throw new \InvalidArgumentException("{$prefix}：机台不存在");
                }
                if ($machine->type !== $expectedMachineType) {
                    throw new \InvalidArgumentException("{$prefix}：机台类型不匹配，工序「{$opType}」需要「{$expectedMachineType}」类型机台");
                }
                if ((int) $machine->workshop_id !== $workshopId) {
                    throw new \InvalidArgumentException("{$prefix}：机台不属于该生产车间（{$workshop->name}）");
                }
            }
        } else {
            // 人工线工序：设备可在排产阶段选择（工艺确认阶段允许为空）；若已指定则须存在、类型与工序对应、车间一致；不得指定机台
            if (! empty($op['machine_id'])) {
                throw new \InvalidArgumentException("{$prefix}：人工线工序不能同时指定机台");
            }
            $lineId = (int) ($op['line_id'] ?? 0);
            if ($lineId) {
                $line = Machine::find($lineId);
                if (! $line) {
                    throw new \InvalidArgumentException("{$prefix}：产线不存在");
                }
                if (! in_array($line->type, Machine::LINE_TYPES, true)) {
                    throw new \InvalidArgumentException("{$prefix}：产线类型不匹配，须为喷漆、效果或包装产线");
                }
                if ($line->type !== $expectedMachineType) {
                    throw new \InvalidArgumentException("{$prefix}：产线类型不匹配，工序「{$opType}」需要「{$expectedMachineType}」类型产线");
                }
                if ((int) $line->workshop_id !== $workshopId) {
                    throw new \InvalidArgumentException("{$prefix}：产线不属于该生产车间（{$workshop->name}）");
                }
            }
        }

        if (array_key_exists('std_hours', $op) && $op['std_hours'] !== null && (! is_numeric($op['std_hours']) || (float) $op['std_hours'] < 0)) {
            throw new \InvalidArgumentException("{$prefix}：标准工时(std_hours)必须为不小于 0 的数值");
        }
        if (($op['owner_id'] ?? null) !== null && ! User::whereKey((int) $op['owner_id'])->exists()) {
            throw new \InvalidArgumentException("{$prefix}：工序负责人(owner_id)不存在");
        }
        if (isset($op['remark']) && mb_strlen((string) $op['remark']) > 255) {
            throw new \InvalidArgumentException("{$prefix}：备注(remark)不能超过 255 字");
        }

        // 工序数量：可空（默认取任务数量）；指定则须为不小于 1 的整数
        $qty = null;
        if (array_key_exists('qty', $op) && $op['qty'] !== null && $op['qty'] !== '') {
            if (! is_numeric($op['qty']) || (int) $op['qty'] < 1) {
                throw new \InvalidArgumentException("{$prefix}：数量(qty)必须为不小于 1 的整数");
            }
            $qty = (int) $op['qty'];
        }

        return [
            'seq' => 0, // 由调用方按数组顺序重排
            'op_type' => $opType,
            'op_name' => $opName,
            'workshop_id' => $workshopId,
            'machine_id' => $machineId ?: null,
            'line_id' => $lineId ?: null,
            'std_hours' => $op['std_hours'] ?? null,
            'owner_id' => $op['owner_id'] ?? null,
            'remark' => $op['remark'] ?? null,
            'qty' => $qty ?? (int) $task->qty,
        ];
    }

    /**
     * 工序行装饰：补车间/机台/产线/负责人名称（输出契约字段 workshop_name 等）
     */
    private function decorateOp(array $op): array
    {
        $workshop = ! empty($op['workshop_id']) ? Workshop::find($op['workshop_id']) : null;
        $machine = ! empty($op['machine_id']) ? Machine::find($op['machine_id']) : null;
        $line = ! empty($op['line_id']) ? Machine::find($op['line_id']) : null;
        $owner = ! empty($op['owner_id']) ? User::find($op['owner_id']) : null;

        return [
            'seq' => (int) ($op['seq'] ?? 0),
            'op_type' => $op['op_type'] ?? null,
            'op_name' => $op['op_name'] ?? null,
            'workshop_id' => $op['workshop_id'] ?? null,
            'workshop_name' => $workshop?->name,
            'machine_id' => $op['machine_id'] ?? null,
            'machine_name' => $machine?->name,
            'line_id' => $op['line_id'] ?? null,
            'line_name' => $line?->name,
            'std_hours' => ($op['std_hours'] ?? null) !== null ? (float) $op['std_hours'] : null,
            'owner_id' => $op['owner_id'] ?? null,
            'owner_name' => $owner?->name,
            'remark' => $op['remark'] ?? null,
            'qty' => ($op['qty'] ?? null) !== null ? (int) $op['qty'] : null,
        ];
    }

    /**
     * 领料清单（GET /orders/{id}/pick-list）—— 只读计算，不落库
     *
     * - 数据源：每个订单明细行产品的「已发布 BOM」（取 version 最大一条）
     * - 无已发布 BOM 的行返回 items: [] 且 bom_id 等为 null
     * - required_qty = round(unit_qty × 行数量 × (1 + loss_rate/100), 4)
     * - 已领恒为 0，仓库批次/库存可用量恒为 null（前端显示 -）
     */
    public function pickList(Order $order): array
    {
        $bomLines = [];
        $linesData = [];
        $itemCount = 0;

        foreach ($order->lines()->with('product')->get() as $line) {
            $bom = $line->product ? $this->publishedBom($line->product) : null;

            $items = [];
            if ($bom) {
                if (! isset($bomLines[$bom->id])) {
                    $bomLines[$bom->id] = $bom->lines()->get();
                }
                $specByCode = Material::query()->whereIn('code', $bomLines[$bom->id]->pluck('item_code'))->pluck('spec', 'code');

                foreach ($bomLines[$bom->id] as $bl) {
                    $unitQty = (float) $bl->qty;
                    $lossRate = (float) $bl->loss_rate;
                    $required = round($unitQty * (float) $line->qty * (1 + $lossRate / 100), 4);

                    $items[] = [
                        'seq' => (int) $bl->seq,
                        'item_code' => $bl->item_code,
                        'item_name' => $bl->item_name,
                        'spec' => $specByCode[$bl->item_code] ?? null,
                        'unit' => $bl->unit,
                        'unit_qty' => $unitQty,
                        'loss_rate' => $lossRate,
                        'required_qty' => $required,
                        'issued_qty' => 0,
                        'remain_qty' => $required,
                        'batch_no' => null,
                        'available_qty' => null,
                        'is_key' => (int) $bl->is_key,
                    ];
                }
                $itemCount += count($items);
            }

            $linesData[] = [
                'line_no' => $line->line_no,
                'product_id' => $line->product_id,
                'product_code' => $line->product?->code,
                'product_name' => $line->product?->name,
                'qty' => (int) $line->qty,
                'bom_id' => $bom?->id,
                'bom_version' => $bom?->version,
                'bom_status' => $bom?->status,
                'items' => $items,
            ];
        }

        return [
            'order_id' => $order->id,
            'order_no' => $order->order_no,
            'lines' => $linesData,
            'summary' => ['item_count' => $itemCount],
        ];
    }

    /**
     * 产品已发布 BOM（取 version 最大一条）
     */
    private function publishedBom(Product $product): ?Bom
    {
        return Bom::where('product_id', $product->id)
            ->where('status', Bom::STATUS_PUBLISHED)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 确认工艺（route_confirm → pending；pending 可重新确认）
     *
     * 前置：全部任务已配置工艺路线且均为已发布版本。
     */
    public function confirmRoute(Order $order, ?int $userId = null): Order
    {
        if (! in_array($order->status, [Order::STATUS_ROUTE_CONFIRM, Order::STATUS_PENDING], true)) {
            throw new \InvalidArgumentException('仅工艺确认中/待排产工单可执行工艺确认');
        }

        $missing = [];
        foreach ($order->lines()->with('tasks')->get() as $line) {
            foreach ($line->tasks as $task) {
                $route = $task->route_id ? PlmRoute::find($task->route_id) : null;
                if (! $route) {
                    $missing[] = $task->task_no;
                    continue;
                }
                if ($route->status !== PlmRoute::STATUS_PUBLISHED) {
                    throw new \InvalidArgumentException("任务 {$task->task_no} 工艺路线「{$route->version}」未发布，请先发布或更换路线");
                }
            }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException('以下任务未配置工艺路线：'.implode('、', $missing).'（请先在编辑中配置）');
        }

        $from = $order->status;
        $order->update(['status' => Order::STATUS_PENDING]);
        $this->logAction($order, WorkOrderLog::ACTION_ROUTE_CONFIRM, $from, Order::STATUS_PENDING, $userId, '工艺路线确认完成，工单进入待排产');

        // 订单行状态同步为待排产
        OrderLine::where('order_id', $order->id)->update(['status' => OrderLine::STATUS_PENDING]);

        return $order->fresh();
    }

    /**
     * 开始生产/恢复生产（pending/scheduled/paused → in_progress）
     */
    public function startOrder(Order $order, ?int $userId = null): Order
    {
        if (! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_SCHEDULED, Order::STATUS_PAUSED], true)) {
            throw new \InvalidArgumentException('仅待排产/已排产/已停工工单可开始生产');
        }

        $from = $order->status;
        if ($from === Order::STATUS_PAUSED) {
            // 停工后开工恢复：暂停任务恢复为生产中
            $taskIds = $order->lines()->with('tasks:id,order_line_id')->get()->flatMap(fn ($l) => $l->tasks->pluck('id'))->all();
            if ($taskIds !== []) {
                DB::transaction(function () use ($order, $taskIds) {
                    ProdTask::whereIn('id', $taskIds)
                        ->where('status', ProdTask::STATUS_PAUSED)
                        ->update(['status' => ProdTask::STATUS_IN_PROGRESS]);

                    // 恢复时重新按报工事实决定甘特状态：有已报工量的段回到进行中，
                    // 尚未报工的段回到待执行；已完成/打断留档段不被覆盖。
                    TaskSchedule::whereIn('task_id', $taskIds)
                        ->where('status', TaskSchedule::STATUS_PAUSED)
                        ->where('is_interrupted', 0)
                        ->where('done_qty', '>', 0)
                        ->update(['status' => TaskSchedule::STATUS_RUNNING]);
                    TaskSchedule::whereIn('task_id', $taskIds)
                        ->where('status', TaskSchedule::STATUS_PAUSED)
                        ->where('is_interrupted', 0)
                        ->where('done_qty', '<=', 0)
                        ->update(['status' => TaskSchedule::STATUS_PENDING]);
                });
            }
        }
        $order->update(['status' => Order::STATUS_IN_PROGRESS, 'started_at' => now()]);
        $this->logAction($order, WorkOrderLog::ACTION_START, $from, Order::STATUS_IN_PROGRESS, $userId);

        return $order->fresh();
    }

    /**
     * 完结入库（scheduled/in_progress → produced，并置所有任务/排程为 done）
     */
    public function completeOrder(Order $order, ?int $userId = null): Order
    {
        if (! in_array($order->status, [Order::STATUS_SCHEDULED, Order::STATUS_IN_PROGRESS, Order::STATUS_PRODUCED], true)) {
            throw new \InvalidArgumentException('仅已排产/生产中/已入库工单可确认完结');
        }

        $from = $order->status;
        DB::transaction(function () use ($order) {
            // 完结是生产事实的确认，不能把“按钮操作”当成报工数据。
            // 锁住订单行和任务，避免校验通过后并发冲减又让订单带着未完成状态进入 produced。
            $lineIds = $order->lines()->lockForUpdate()->pluck('id');
            $tasks = ProdTask::whereIn('order_line_id', $lineIds)
                ->lockForUpdate()
                ->get(['id', 'task_no', 'qty', 'done_qty', 'status']);
            $incomplete = $tasks->filter(fn (ProdTask $task) =>
                $task->status !== ProdTask::STATUS_DONE || (int) $task->done_qty < (int) $task->qty
            );

            if ($incomplete->isNotEmpty()) {
                $detail = $incomplete->map(fn (ProdTask $task) =>
                    "{$task->task_no}（{$task->done_qty}/{$task->qty}）"
                )->implode('、');
                throw new \InvalidArgumentException("工单尚未完成，不能完结；请先完成末工序报工：{$detail}");
            }

            // 任务/排程状态由报工联动维护，这里不再用 qty 直接覆盖，避免制造虚假完工。
            $order->update(['status' => Order::STATUS_PRODUCED, 'completed_at' => $order->completed_at ?? now()]);
        });
        $this->logAction($order, WorkOrderLog::ACTION_COMPLETE, $from, Order::STATUS_PRODUCED, $userId);

        return $order->fresh();
    }

    /**
     * 撤销（scheduled/in_progress → pending，清除开始/完结时间）
     */
    public function revokeOrder(Order $order, ?int $userId = null): Order
    {
        if (! in_array($order->status, [Order::STATUS_SCHEDULED, Order::STATUS_IN_PROGRESS], true)) {
            throw new \InvalidArgumentException('仅已排产/生产中工单可撤销');
        }

        $from = $order->status;
        $order->update(['status' => Order::STATUS_PENDING, 'started_at' => null, 'completed_at' => null]);
        $this->logAction($order, WorkOrderLog::ACTION_REVOKE, $from, Order::STATUS_PENDING, $userId);

        return $order->fresh();
    }

    /**
     * 停工（in_progress → paused，生产中任务暂停；再次开工恢复）
     *
     * POST /api/orders/{id}/pause
     */
    public function pauseOrder(Order $order, ?int $userId = null): Order
    {
        if (! in_array($order->status, [Order::STATUS_IN_PROGRESS, Order::STATUS_PAUSED], true)) {
            throw new \InvalidArgumentException('仅生产中工单可停工');
        }
        if ($order->status === Order::STATUS_PAUSED) {
            return $order->fresh(); // 幂等：已是停工状态
        }

        $from = $order->status;
        DB::transaction(function () use ($order) {
            $taskIds = $order->lines()->with('tasks:id,order_line_id')->get()->flatMap(fn ($l) => $l->tasks->pluck('id'))->all();
            if ($taskIds !== []) {
                $activeTaskIds = ProdTask::whereIn('id', $taskIds)
                    ->where('status', ProdTask::STATUS_IN_PROGRESS)
                    ->pluck('id');

                ProdTask::whereIn('id', $activeTaskIds)
                    ->update(['status' => ProdTask::STATUS_PAUSED]);

                // 订单停工必须同步到甘特图数据源；已完成段和插单打断留档段保留事实，
                // 仅暂停当前生产任务尚未完成的正式排程行。
                TaskSchedule::whereIn('task_id', $activeTaskIds)
                    ->where('status', '!=', TaskSchedule::STATUS_DONE)
                    ->where('is_interrupted', 0)
                    ->update(['status' => TaskSchedule::STATUS_PAUSED]);
            }
            $order->update(['status' => Order::STATUS_PAUSED]);
        });
        $this->logAction($order, WorkOrderLog::ACTION_PAUSE, $from, Order::STATUS_PAUSED, $userId, '工单停工，生产中任务暂停');

        return $order->fresh();
    }

    /**
     * 中止（in_progress/paused → aborted，停止生产不再继续）
     *
     * POST /api/orders/{id}/abort
     */
    public function abortOrder(Order $order, ?int $userId = null): Order
    {
        if (! in_array($order->status, [Order::STATUS_IN_PROGRESS, Order::STATUS_PAUSED], true)) {
            throw new \InvalidArgumentException('仅生产中/已停工工单可中止');
        }

        $from = $order->status;
        DB::transaction(function () use ($order) {
            $taskIds = $order->lines()->with('tasks:id,order_line_id')->get()->flatMap(fn ($l) => $l->tasks->pluck('id'))->all();
            if ($taskIds !== []) {
                ProdTask::whereIn('id', $taskIds)
                    ->whereIn('status', [ProdTask::STATUS_IN_PROGRESS, ProdTask::STATUS_PAUSED])
                    ->update(['status' => ProdTask::STATUS_PAUSED]);
            }
            $order->update(['status' => Order::STATUS_ABORTED]);
        });
        $this->logAction($order, WorkOrderLog::ACTION_ABORT, $from, Order::STATUS_ABORTED, $userId, '工单中止');

        return $order->fresh();
    }

    /**
     * 关闭（任意非终态 → closed，订单归档）
     *
     * POST /api/orders/{id}/close
     */
    public function closeOrder(Order $order, ?int $userId = null): Order
    {
        if (in_array($order->status, [Order::STATUS_PRODUCED, Order::STATUS_ABORTED, Order::STATUS_CLOSED], true)) {
            throw new \InvalidArgumentException('已入库/已中止/已关闭工单不可重复关闭');
        }

        $from = $order->status;
        $order->update(['status' => Order::STATUS_CLOSED]);
        $this->logAction($order, WorkOrderLog::ACTION_CLOSE, $from, Order::STATUS_CLOSED, $userId, '工单关闭');

        return $order->fresh();
    }

    /**
     * 工序进度（按 op_type 聚合计划量/已报工量）
     *
     * - 计划量 = Σ task_schedules.qty（按 op_type 分组）
     * - 完成量 = Σ work_reports.qty（经 schedule_id 关联 op_type，正负相抵）
     */
    public function getProgress(Order $order): array
    {
        $taskIds = ProdTask::whereIn('order_line_id', $order->lines()->pluck('id'))->pluck('id')->all();

        return $this->progressByTaskIds($taskIds);
    }

    /**
     * 单任务（工单）工序进度：工单中心按产品拆解后，每行展示所属工单自身的进度
     */
    public function getTaskProgress(ProdTask $task): array
    {
        return $this->progressByTaskIds([$task->id]);
    }

    /**
     * 按任务 id 集合聚合工序进度（订单级/工单级共用）
     */
    private function progressByTaskIds(array $taskIds): array
    {
        $grouped = $this->progressGroupedByTask($taskIds);

        // 合并所有任务的 stages（按 op_type 累加 plan/done，重算 percent）→ 订单级口径
        $acc = ['stages' => [], 'percent' => 0];
        $seen = [];
        $totalPlan = 0;
        $totalDone = 0;

        foreach ($grouped as $taskProgress) {
            foreach ($taskProgress['stages'] as $s) {
                $op = $s['op_type'];
                $totalPlan += $s['plan_qty'];
                $totalDone += $s['done_qty'];
                if (isset($seen[$op])) {
                    $idx = $seen[$op];
                    $acc['stages'][$idx]['plan_qty'] += $s['plan_qty'];
                    $acc['stages'][$idx]['done_qty'] += $s['done_qty'];
                    $acc['stages'][$idx]['percent'] = $acc['stages'][$idx]['plan_qty'] > 0
                        ? min(100, (int) round(($acc['stages'][$idx]['done_qty'] / $acc['stages'][$idx]['plan_qty']) * 100))
                        : 0;
                } else {
                    $seen[$op] = count($acc['stages']);
                    $acc['stages'][] = $s;
                }
            }
        }

        $percent = $totalPlan > 0 ? min(100, (int) round($totalDone / $totalPlan * 100)) : 0;

        return ['stages' => $acc['stages'], 'percent' => $percent];
    }

    /**
     * 按任务分组聚合工序进度（订单详情产品行共用，一次查询避免 N+1）
     *
     * @return array<int, array{stages: array<int, array{op_type: string, op_label: string, plan_qty: int, done_qty: int, percent: int}>, percent: int}>
     */
    private function progressGroupedByTask(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $plans = DB::table('task_schedules')
            ->whereIn('task_id', $taskIds)
            ->select('task_id', 'op_type', DB::raw('SUM(qty) as plan'))
            ->groupBy('task_id', 'op_type')
            ->get();

        $dones = DB::table('task_schedules')
            ->join('work_reports', 'work_reports.schedule_id', '=', 'task_schedules.id')
            ->whereIn('task_schedules.task_id', $taskIds)
            ->select('task_schedules.task_id', 'task_schedules.op_type', DB::raw('SUM(work_reports.qty) as done'))
            ->groupBy('task_schedules.task_id', 'task_schedules.op_type')
            ->get();

        $opOrder = [
            RouteOp::OP_MOLDING_INJECTION,
            RouteOp::OP_MOLDING_BLOW,
            RouteOp::OP_PAINT,
            RouteOp::OP_EFFECT,
            RouteOp::OP_PACK,
        ];

        $result = [];
        foreach ($taskIds as $taskId) {
            $stages = [];
            $totalPlan = 0;
            $totalDone = 0;

            foreach ($opOrder as $op) {
                $plan = (int) ($plans->firstWhere(fn ($r) => (int) $r->task_id === $taskId && $r->op_type === $op)?->plan ?? 0);
                if ($plan <= 0) {
                    continue;
                }
                $done = max(0, (int) ($dones->firstWhere(fn ($r) => (int) $r->task_id === $taskId && $r->op_type === $op)?->done ?? 0));
                $totalPlan += $plan;
                $totalDone += $done;
                $stages[] = [
                    'op_type' => $op,
                    'op_label' => RouteOp::OP_LABELS[$op] ?? $op,
                    'plan_qty' => $plan,
                    'done_qty' => max(0, $done),
                    'percent' => $plan > 0 ? min(100, (int) round($done / $plan * 100)) : 0,
                ];
            }

            $percent = $totalPlan > 0 ? min(100, (int) round($totalDone / $totalPlan * 100)) : 0;

            $result[$taskId] = ['stages' => $stages, 'percent' => $percent];
        }

        return $result;
    }

    /**
     * 任务级执行元数据（订单详情产品行用，一次性聚合避免 N+1）：
     *
     * - actual_start 实际开工：该任务首条正报工的开始时间；无报工为 null
     * - actual_end   实际完工：该任务末条正报工的结束时间（近似完工时点）
     * - total_hours  累计工时：Σ(各排程段 plan_end − plan_start) 小时，按排程计划时长累计
     *                  （实际工时需报工带时长字段，二期计件口径接入后替换）
     *
     * 通过 setAttribute 附加到每个任务，随 lines.tasks 序列化返回。
     */
    public function attachTaskExecutionMeta(Order $order): Order
    {
        $tasks = $order->lines->pluck('tasks')->flatten();
        if ($tasks->isEmpty()) {
            return $order;
        }

        $taskIds = $tasks->pluck('id')->all();

        // 报工聚合：sign=1 正记录按实际时间段排序，首条开始=实际开工、末条结束=实际完工
        $reports = WorkReport::whereIn('task_id', $taskIds)
            ->where('sign', 1)
            ->orderByRaw('COALESCE(start_time, report_time)')
            ->get(['task_id', 'start_time', 'end_time', 'report_time']);

        $firstByTask = $reports->groupBy('task_id')->map->first();
        $lastByTask = $reports->groupBy('task_id')->map->last();

        // 排程时长聚合：累计工时 = Σ(plan_end − plan_start)
        $schedules = TaskSchedule::whereIn('task_id', $taskIds)
            ->whereNotNull('plan_start')
            ->whereNotNull('plan_end')
            ->get(['task_id', 'plan_start', 'plan_end']);

        $hoursByTask = [];
        foreach ($schedules as $sc) {
            // 注意：本项目 Carbon 的 diffInMinutes $absolute 默认 false（带符号），须显式 true
            $minutes = max(0, $sc->plan_end->diffInMinutes($sc->plan_start, true));
            $hoursByTask[$sc->task_id] = ($hoursByTask[$sc->task_id] ?? 0) + $minutes / 60;
        }

        // 工序级进度（stages）：按任务分组一次聚合，避免 N+1
        $progressByTask = $this->progressGroupedByTask($taskIds);

        foreach ($tasks as $task) {
            $firstReport = $firstByTask->get($task->id);
            $lastReport = $lastByTask->get($task->id);
            $task->setAttribute('actual_start', ($firstReport?->start_time ?? $firstReport?->report_time)?->format('Y-m-d H:i:s'));
            $task->setAttribute('actual_end', ($lastReport?->end_time ?? $lastReport?->report_time)?->format('Y-m-d H:i:s'));
            $task->setAttribute('total_hours', isset($hoursByTask[$task->id]) ? round($hoursByTask[$task->id], 2) : null);
            $task->setAttribute('progress', $progressByTask[$task->id] ?? ['stages' => [], 'percent' => 0]);
        }

        return $order;
    }
}
