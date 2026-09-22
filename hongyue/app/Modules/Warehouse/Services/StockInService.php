<?php

namespace App\Modules\Warehouse\Services;

use App\Models\Order;
use App\Models\ProdTask;
use App\Models\StockIn;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\Order\Services\OrderService;
use App\Modules\Warehouse\Exceptions\StockInException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * 分批入库服务（C8：可入库量 = 报工完成量 − 已入库量；分批独立单据）
 *
 * - 可入库量只统计**最后一道工序（成品）**的任务级/成品报工，中间工序完成不入库
 * - 超量强校验（qty ≤ available，否则 422）
 * - 入库后任务 stocked_qty 冗余同步（可对账 Σstock_ins）、订单"可发货量 = Σ已入库"
 * - 单据默认 queued，交由 K3StockInPushService（队列+重试+幂等）
 */
class StockInService
{
    public function __construct(
        private readonly K3StockInPushService $pushService,
        private readonly OrderService $orderService,
    ) {
    }

    /**
     * 可入库量计算（C8 口径）
     *
     * @return array{task_id: int, reported_qty: int, stocked_qty: int, available_qty: int}
     */
    public function availableQty(int $taskId): array
    {
        $task = ProdTask::with('line.order')->findOrFail($taskId);

        // 成品口径：最后一道工序（max seq）的全部排程段 + 任务级报工（未指定工序）；
        // 插单/拆段后同一道末工序可能有历史段和当前段，不能只取一条 id。
        $lastSeq = TaskSchedule::where('task_id', $taskId)->max('seq');
        $lastScheduleIds = $lastSeq === null
            ? collect()
            : TaskSchedule::where('task_id', $taskId)->where('seq', $lastSeq)->pluck('id');
        $reported = (int) $task->reports()
            ->where(function ($q) use ($lastScheduleIds) {
                $q->whereNull('schedule_id');
                if ($lastScheduleIds->isNotEmpty()) {
                    $q->orWhereIn('schedule_id', $lastScheduleIds);
                }
            })
            ->sum(\Illuminate\Support\Facades\DB::raw('qty'));
        $stocked = (int) $task->stocked_qty;

        return [
            'task_id' => $task->id,
            'task_no' => $task->task_no,
            'reported_qty' => max(0, $reported),
            'stocked_qty' => $stocked,
            'available_qty' => max(0, $reported - $stocked),
        ];
    }

    /**
     * 待入库任务列表（入库管理首页）：所有可入库量 > 0 的任务
     *
     * 口径与 availableQty() 完全一致：只统计最后一道工序（max seq）报工 + 任务级报工（未指定工序），
     * 减去已入库量后可入库量仍 > 0 才出现在列表，专供仓库直接发起入库，避免手动猜任务 ID。
     */
    public function pendingTasks(int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);

        // 每个任务最后一道工序 id（成品口径；无排程任务 → 空）
        $lastScheduleIds = DB::table('task_schedules as ts')
            ->join(
                DB::raw('(SELECT task_id, MAX(seq) AS max_seq FROM task_schedules GROUP BY task_id) AS m'),
                fn ($join) => $join->on('ts.task_id', '=', 'm.task_id')->on('ts.seq', '=', 'm.max_seq'),
            )
            ->pluck('ts.id');

        // 成品报工汇总：最后工序报工 + 任务级报工（未指定工序）
        $reportedMap = WorkReport::query()
            ->where(fn ($q) => $q->whereNull('schedule_id')->orWhereIn('schedule_id', $lastScheduleIds))
            ->selectRaw('task_id, SUM(qty) AS reported')
            ->groupBy('task_id')
            ->pluck('reported', 'task_id')
            ->map(fn ($v) => (int) $v);

        if ($reportedMap->isEmpty()) {
            return new LengthAwarePaginator([], 0, $perPage, $page, ['path' => request()->path(), 'query' => request()->query()]);
        }

        $items = ProdTask::with(['line.order', 'product', 'schedules'])
            ->whereIn('id', $reportedMap->keys())
            ->get()
            ->map(function (ProdTask $task) use ($reportedMap): array {
                $reported = max(0, (int) $reportedMap->get($task->id, 0));
                $stocked = (int) $task->stocked_qty;

                return [
                    'task_id' => $task->id,
                    'task_no' => $task->task_no,
                    'product' => $task->product ? ['id' => $task->product->id, 'code' => $task->product->code, 'name' => $task->product->name] : null,
                    'order_no' => $task->line?->order?->order_no,
                    'customer_name' => $task->line?->order?->customer_name,
                    'task_qty' => (int) $task->qty,
                    // 关系默认按主键排序，插单拆段后最后插入的行不一定是末工序；
                    // 成品入库列表必须按工序序号定位真实末工序。
                    'last_op_name' => $task->schedules
                        ->where('is_interrupted', 0)
                        ->sortBy([['seq', 'desc'], ['id', 'desc']])
                        ->first()?->op_name,
                    'reported_qty' => $reported,
                    'stocked_qty' => $stocked,
                    'available_qty' => max(0, $reported - $stocked),
                ];
            })
            ->filter(fn (array $item): bool => $item['available_qty'] > 0)
            ->sortByDesc('available_qty')
            ->values();

        /** @var Collection<int, array> $items */
        return new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => request()->path(), 'query' => request()->query()],
        );
    }

    /**
     * 确认入库（分批）
     *
     * @return array{stock_in: StockIn, available: array}
     *
     * @throws StockInException
     */
    public function create(int $taskId, int $qty, ?int $userId = null, ?string $remark = null): array
    {
        if ($qty <= 0) {
            throw new StockInException('入库数量必须大于 0', 'qty_invalid');
        }

        $task = ProdTask::with('product', 'line.order', 'mold')->find($taskId);
        if (! $task) {
            throw new StockInException('任务不存在', 'task_not_found', [], 404);
        }

        $available = $this->availableQty($taskId);

        if ($qty > $available['available_qty']) {
            throw new StockInException(
                "入库量超限：可入库量 {$available['available_qty']}（报工完成 {$available['reported_qty']} − 已入库 {$available['stocked_qty']}）",
                'over_available_qty',
                $available,
            );
        }

        $stockIn = DB::transaction(function () use ($task, $qty, $userId, $remark, $available) {
            $stockIn = StockIn::create([
                'ins_no' => $this->generateInsNo(),
                'task_id' => $task->id,
                'product_id' => $task->product_id,
                'qty' => $qty,
                'ins_status' => StockIn::INS_STATUS_CONFIRMED,
                'k3_push_status' => StockIn::K3_QUEUED,
                'created_by' => $userId,
                'remark' => $remark,
            ]);

            // 冗余累计（可对账 Σstock_ins.qty）
            $task->increment('stocked_qty', $qty);

            // 订单可发货量联动（Σ已入库）
            $order = $task->line?->order;
            if ($order) {
                $this->orderService->syncStatus($order);
            }

            return $stockIn;
        });

        // 推送金蝶（队列 + 重试 + 幂等，K3 不可用不影响入库业务）
        try {
            $this->pushService->pushAsync($stockIn);
        } catch (\Throwable $e) {
            // 推送编排异常不阻断入库（金蝶故障降级，C9/C10 由重试/手动补齐）
        }

        return [
            'stock_in' => $stockIn->load('task:id,task_no,product_id,qty', 'task.product:id,code,name'),
            'available' => $this->availableQty($taskId),
        ];
    }

    /**
     * 入库单列表
     */
    public function index(array $filters = [], int $perPage = 15)
    {
        return StockIn::query()
            ->with(['task:id,task_no,product_id,qty,status', 'task.product:id,code,name', 'creator:id,name'])
            ->when($filters['task_id'] ?? null, fn ($q, $v) => $q->where('task_id', (int) $v))
            ->when($filters['k3_push_status'] ?? null, fn ($q, $v) => $q->where('k3_push_status', $v))
            ->when($filters['ins_status'] ?? null, fn ($q, $v) => $q->where('ins_status', $v))
            ->orderByDesc('id')
            ->paginate(min($perPage, 100));
    }

    /**
     * 入库单号：RK + 日期 + 随机后缀（每批独立）
     */
    public function generateInsNo(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $no = sprintf('RK-%s-%s', now()->format('Ymd'), strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)));
            if (! StockIn::where('ins_no', $no)->exists()) {
                return $no;
            }
        }

        return sprintf('RK-%s-%s', now()->format('YmdHis'), strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)));
    }
}
