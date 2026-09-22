<?php

namespace App\Modules\Order\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProdTask;
use App\Modules\Order\Requests\OrderRequest;
use App\Modules\Order\Services\ExcelImportService;
use App\Modules\Order\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 订单中心控制器（二期：手工建单 + Excel 导入；四期接金蝶拉单）
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ExcelImportService $excelImportService,
    ) {
    }

    /**
     * 工单中心列表（工单粒度：订单按产品拆解，一行=一个工单，工单号唯一）
     *
     * - 过滤：status/src 按所属订单；priority/keyword 支持工单级（工单号/产品名/订单号/客户）
     * - with_progress=1 时带出该工单自身工序进度
     *
     * GET /api/orders
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProdTask::query()
            ->with(['line.order', 'product', 'parentTask:id,task_no'])
            ->whereHas('line.order', function ($q) use ($request) {
                $q->when($request->input('status'), fn ($w, $status) => $w->where('status', $status))
                    ->when($request->input('src'), fn ($w, $src) => $w->where('src', $src));
            })
            ->when($request->input('priority'), fn ($q, $priority) => $q->where('priority', (int) $priority))
            ->when($request->input('keyword'), fn ($q, $keyword) => $q->where(function ($w) use ($keyword) {
                // 工单级：工单号 / 产品名 / 产品编码；订单级：订单号 / 客户 / 金蝶单号
                $w->where('task_no', 'like', "%{$keyword}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%"))
                    ->orWhereHas('line.order', fn ($o) => $o->where(fn ($x) => $x->where('order_no', 'like', "%{$keyword}%")
                        ->orWhere('customer_name', 'like', "%{$keyword}%")
                        ->orWhere('k3_bill_no', 'like', "%{$keyword}%")));
            }))
            ->orderByDesc('id');

        $perPage = min((int) $request->input('per_page', 15), 100);

        $result = $query->paginate($perPage);

        $result->getCollection()->transform(function (ProdTask $task) use ($request) {
            $order = $task->line?->order;

            $row = [
                'id' => $task->id,
                'task_no' => $task->task_no,
                'order_id' => $order?->id,
                'order_no' => $order?->order_no,
                'customer_name' => $order?->customer_name,
                'src' => $order?->src,
                'product_id' => $task->product_id,
                'product_name' => $task->product?->name,
                'product_code' => $task->product?->code,
                'qty' => $task->qty,
                'done_qty' => $task->done_qty,
                'mold_id' => $task->mold_id,
                'priority' => $task->priority,
                'delivery_date' => $task->delivery_date?->format('Y-m-d'),
                // 订单状态（8 态）：操作可用性与过滤沿用订单级状态机
                'status' => $order?->status,
                // 工单自身状态（unscheduled/scheduled/in_progress/paused/done）
                'task_status' => $task->status,
                'is_locked' => (bool) $task->is_locked,
                'parent_task_id' => $task->parent_task_id,
                'parent_task_no' => $task->parentTask?->task_no,
                'split_index' => $task->split_index,
                'split_total' => $task->split_total,
                'is_split_child' => $task->parent_task_id !== null,
            ];

            if ($request->boolean('with_progress')) {
                $row['progress'] = $this->orderService->getTaskProgress($task);
            }

            return $row;
        });

        return $this->ok($result);
    }

    /**
     * 生产拆单：一个未开工任务拆为多个可独立排产的子任务。
     *
     * POST /api/orders/tasks/{taskId}/split
     * body: { parts: [{ qty: 1000 }, ...] }
     */
    public function splitTask(Request $request, int $taskId): JsonResponse
    {
        $data = $request->validate([
            'parts' => ['required', 'array', 'min:2', 'max:20'],
            'parts.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        $task = ProdTask::withoutGlobalScope('leaf_task')->findOrFail($taskId);

        try {
            $result = $this->orderService->splitTask($task, $data['parts'], $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 409, 409);
        }

        return $this->ok($result, '生产拆单完成', 201);
    }

    /**
     * 订单详情（含明细 + 任务）
     *
     * GET /api/orders/{id}
     */
    public function show(int $id): JsonResponse
    {
        $order = Order::with([
            'lines.product',
            'lines.tasks.product',
            'lines.tasks.mold',
            'creator:id,name,employee_no',
        ])->findOrFail($id);

        if (request()->boolean('with_schedules')) {
            $order->setAttribute('tasks_with_schedules', \App\Models\ProdTask::where('order_line_id', $order->lines->pluck('id'))
                ->with('schedules.machine')->get());
        }

        // 任务级执行元数据：实际开工/完工（报工聚合）、累计工时（排程计划时长）
        $this->orderService->attachTaskExecutionMeta($order);

        return $this->ok($order);
    }

    /**
     * 订单明细（架构 §4：GET /api/orders/{id}/lines）
     */
    public function lines(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        return $this->ok($order->lines()->with('product', 'tasks')->get());
    }

    /**
     * 手工建单（多产品明细）
     *
     * POST /api/orders
     */
    public function store(OrderRequest $request): JsonResponse
    {
        $order = $this->orderService->createOrder($request->validated(), $request->user()?->id);

        return $this->ok($order, '订单创建成功（已生成生产任务）', 201);
    }

    /**
     * Excel/CSV 导入订单
     *
     * POST /api/orders/import-excel  (multipart: file)
     */
    public function importExcel(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls,ods', 'max:5120'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');

        try {
            $result = $this->excelImportService->import($file->getRealPath(), $request->user()?->id);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 422,
                'message' => '导入文件解析失败：'.$e->getMessage(),
                'data' => null,
            ], 422);
        }

        if (! $result['success']) {
            return response()->json([
                'code' => 422,
                'message' => '无可导入的合法行',
                'data' => $result,
            ], 422);
        }

        return response()->json([
            'code' => 0,
            'message' => sprintf('导入成功 %d 行，跳过 %d 行', $result['imported_count'], $result['skipped_count']),
            'data' => $result,
        ], $result['skipped_count'] > 0 ? 207 : 201);
    }

    /**
     * 导入模板下载（CSV，UTF-8 BOM）
     *
     * GET /api/orders/import-template
     */
    public function importTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $header = ['产品编码', '数量', '交期', '客户', '优先级', '备注'];
        $examples = [
            ['P-0001', '1000', now()->addDays(30)->format('Y-m-d'), '示例客户', '3', ''],
            ['P-0002', '2000', now()->addDays(45)->format('Y-m-d'), '示例客户', '4', ''],
        ];

        $lines = array_map(fn ($row) => implode(',', $row), array_merge([$header], $examples));

        return response()->streamDownload(function () use ($lines): void {
            echo "\xEF\xBB\xBF".implode("\n", $lines);
        }, '订单导入模板.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * 删除订单（软删除；存在生产中的任务时拒绝）
     *
     * DELETE /api/orders/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $order = Order::with('lines.tasks')->findOrFail($id);

        // 已排产/生产中/已完成的任务不可随订单删除（需先回滚排程）
        $active = $order->lines->pluck('tasks')->flatten()->first(fn ($t) => in_array($t->status, ['scheduled', 'in_progress', 'paused', 'done']));
        if ($active) {
            return response()->json([
                'code' => 409,
                'message' => '订单存在已排产/生产中/已完成任务，不可删除（请先回滚排程）',
                'data' => null,
            ], 409);
        }

        $order->delete();

        return $this->ok(null, '订单已删除');
    }

    /**
     * 编辑工单头信息（已入库不可编辑）
     *
     * PATCH /api/orders/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $data = $request->validate([
            'customer_name' => ['sometimes', 'string', 'max:128'],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'delivery_date' => ['sometimes', 'nullable', 'date'],
            'remark' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $order = $this->orderService->updateOrder($order, $data, $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 409, 409);
        }

        return $this->ok($order, '工单信息已更新');
    }

    /**
     * 配置/调整任务工艺路线（route_confirm / pending 可改）
     *
     * PUT /api/orders/{id}/routes  body: {tasks: [{task_id, route_id}]}
     */
    public function updateRoutes(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $data = $request->validate([
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.task_id' => ['required', 'integer'],
            'tasks.*.route_id' => ['required', 'integer'],
        ]);

        try {
            $order = $this->orderService->updateTaskRoutes($order, $data['tasks'], $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($order->load('lines.tasks.route:id,version'), '任务工艺路线已更新');
    }

    /**
     * 订单级工艺明细（含生效工序：订单快照优先，否则工艺路线库）
     *
     * GET /api/orders/{id}/route-ops
     */
    public function taskRouteOps(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        return $this->ok($this->orderService->taskRouteOps($order));
    }

    /**
     * 编辑订单级工艺明细（route_confirm / pending 可改；只对当前工单生效）
     *
     * PUT /api/orders/{id}/route-ops  body: {tasks: [{task_id, route_id?, ops: [...]}]}
     */
    public function updateTaskRouteOps(Request $request, int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        $data = $request->validate([
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.task_id' => ['required', 'integer'],
            'tasks.*.route_id' => ['nullable', 'integer'],
            'tasks.*.restore_standard' => ['nullable', 'boolean'],
            // 恢复标准路线允许空 ops；空数组若未带 restore_standard，由 service 拦截（业务消息更友好）
            'tasks.*.ops' => ['required_unless:tasks.*.restore_standard,true', 'array'],
        ]);

        try {
            $result = $this->orderService->updateTaskRouteOps($order, $data, $request->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok($result, '工艺明细已更新');
    }

    /**
     * 领料清单（按已发布 BOM 计算，只读不落库）
     *
     * GET /api/orders/{id}/pick-list
     */
    public function pickList(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        return $this->ok($this->orderService->pickList($order));
    }

    /**
     * 确认工艺（route_confirm → pending；校验全部任务工艺已配置且已发布）
     *
     * POST /api/orders/{id}/route-confirm
     */
    public function routeConfirm(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        try {
            $order = $this->orderService->confirmRoute($order, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 422);
        }

        return $this->ok([
            'id' => $order->id,
            'status' => $order->status,
        ], '工艺确认完成，工单已进入待排产');
    }

    /**
     * 开始生产（pending/scheduled → in_progress）
     *
     * POST /api/orders/{id}/start
     */
    public function start(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        try {
            $order = $this->orderService->startOrder($order, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 409, 409);
        }

        return $this->ok($order, '工单已开始生产');
    }

    /**
     * 完结入库（scheduled/in_progress → produced）
     *
     * POST /api/orders/{id}/complete
     */
    public function complete(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        try {
            $order = $this->orderService->completeOrder($order, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 409, 409);
        }

        return $this->ok($order, '工单已完结入库');
    }

    /**
     * 撤销（scheduled/in_progress → pending）
     *
     * POST /api/orders/{id}/revoke
     */
    public function revoke(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        try {
            $order = $this->orderService->revokeOrder($order, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 409, 409);
        }

        return $this->ok($order, '工单已撤销（回到待排产）');
    }

    /**
     * 停工（in_progress → paused，生产中任务暂停；再次开工恢复）
     *
     * POST /api/orders/{id}/pause
     */
    public function pause(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        try {
            $order = $this->orderService->pauseOrder($order, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 409, 409);
        }

        return $this->ok($order, '工单已停工');
    }

    /**
     * 中止（in_progress/paused → aborted）
     *
     * POST /api/orders/{id}/abort
     */
    public function abort(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        try {
            $order = $this->orderService->abortOrder($order, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 409, 409);
        }

        return $this->ok($order, '工单已中止');
    }

    /**
     * 关闭（任意非终态 → closed，订单归档）
     *
     * POST /api/orders/{id}/close
     */
    public function close(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        try {
            $order = $this->orderService->closeOrder($order, request()->user()?->id);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 409, 409);
        }

        return $this->ok($order, '工单已关闭');
    }

    /**
     * 工单操作日志
     *
     * GET /api/orders/{id}/logs
     */
    public function logs(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        return $this->ok($order->logs()->with('operator:id,name')->orderByDesc('id')->paginate(min((int) request()->input('per_page', 20), 100)));
    }

    /**
     * 工序进度（按 op_type 分段）
     *
     * GET /api/orders/{id}/progress
     */
    public function progress(int $id): JsonResponse
    {
        $order = Order::findOrFail($id);

        return $this->ok($this->orderService->getProgress($order));
    }
}
