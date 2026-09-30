<?php

namespace App\Modules\Dashboard\Services;

use App\Models\Machine;
use App\Models\Mold;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use App\Modules\System\Services\ConfigService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 看板聚合服务（工作台 / 订单总览 / 任务看板 / 产能热力 / 报表）
 *
 * 全部只读聚合，数据量小（20 机台 / 60 人），直接实时计算。
 */
class DashboardService
{
    /**
     * 工作台：KPI 统计 + 红灯速览 + 临期订单 + 今日派工
     */
    public function workbench(RedLightService $redLight): array
    {
        $orders = Order::with('lines')->get();
        $tasks = ProdTask::with(['line.order', 'schedules'])->get();
        $machines = Machine::all();
        $redLights = $redLight->list();
        $openRed = array_values(array_filter($redLights, fn ($r) => $r['status'] === 'open'));

        $overdueOrders = $orders->filter(fn (Order $o) => $o->status !== Order::STATUS_PRODUCED && $o->delivery_date && $o->delivery_date->lt(now()))->count();
        $behindTasks = $tasks->filter(fn (ProdTask $t) => (float) $t->deviation_pct > (float) config('dashboard.deviation_pct', 10))->count();

        $reportToday = WorkReport::whereDate('report_time', now()->toDateString());
        $stockInToday = \App\Models\StockIn::whereDate('created_at', now()->toDateString());

        $soonOrders = $orders
            ->filter(fn (Order $o) => $o->status !== Order::STATUS_PRODUCED && $o->delivery_date && $o->delivery_date->gt(now()) && $o->delivery_date->lte(now()->addDays(3)))
            ->sortBy('delivery_date')
            ->take(5)
            ->values()
            ->map(fn (Order $o) => [
                'order_id' => $o->id,
                'order_no' => $o->order_no,
                'customer_name' => $o->customer_name,
                'delivery_date' => $o->delivery_date?->toDateString(),
                'total_qty' => (int) $o->lines()->sum('qty'),
                'progress_pct' => $this->orderProgress($o),
            ])
            ->all();

        $todayTasks = TaskSchedule::query()
            ->with('machine')
            ->whereDate('plan_start', now()->toDateString())
            ->orderBy('plan_start')
            ->take(6)
            ->get()
            ->map(fn (TaskSchedule $s) => [
                'task_id' => (int) $s->task_id,
                'task_no' => $s->task?->task_no,
                'product_code' => $s->task?->product?->code,
                'product_name' => $s->task?->product?->name,
                'machine_code' => $s->machine?->code,
                'plan_start' => $s->plan_start?->toDateTimeString(),
                'plan_end' => $s->plan_end?->toDateTimeString(),
                'status' => $s->status,
                'progress_pct' => $s->qty ? (int) round((($s->done_qty ?? 0) / $s->qty) * 100) : 0,
            ])
            ->all();

        return [
            'stats' => [
                'orders' => [
                    'total' => $orders->count(),
                    'pending' => $orders->where('status', Order::STATUS_PENDING)->count(),
                    'in_progress' => $orders->whereIn('status', [Order::STATUS_SCHEDULED, Order::STATUS_IN_PROGRESS])->count(),
                    'overdue' => $overdueOrders,
                    'done' => $orders->where('status', Order::STATUS_PRODUCED)->count(),
                ],
                'tasks' => [
                    'total' => $tasks->count(),
                    'unscheduled' => $tasks->where('status', ProdTask::STATUS_UNSCHEDULED)->count(),
                    'running' => $tasks->whereIn('status', [ProdTask::STATUS_IN_PROGRESS, ProdTask::STATUS_PAUSED])->count(),
                    'overdue' => $tasks->filter(fn (ProdTask $t) => $t->status !== ProdTask::STATUS_DONE && $t->delivery_date && $t->delivery_date->lt(now()))->count(),
                    'behind' => $behindTasks,
                ],
                'today' => [
                    'report_qty' => (int) $reportToday->where('sign', 1)->sum('qty'),
                    'stock_in_qty' => (int) $stockInToday->sum('qty'),
                    'report_count' => (int) $reportToday->count(),
                ],
                'red_lights' => [
                    'total' => count($openRed),
                    'by_source' => array_values(collect($openRed)->groupBy('source')->map(function (Collection $rows, string $source) {
                        return ['source' => $source, 'source_label' => $rows->first()['source_label'], 'count' => $rows->count()];
                    })->all()),
                ],
                'machines' => [
                    'total' => $machines->count(),
                    'running' => $machines->where('status', Machine::STATUS_RUNNING)->count(),
                    'maintenance' => $machines->where('status', Machine::STATUS_MAINTENANCE)->count(),
                    'utilization_avg' => (int) round(collect($this->loadHeatmap()['rows'])->avg('avg_load') ?? 0),
                ],
            ],
            'top_red_lights' => array_slice($openRed, 0, 5),
            'soon_orders' => $soonOrders,
            'today_tasks' => $todayTasks,
        ];
    }

    /**
     * 订单履约总览（订单级聚合：生产完成、入库完成、交期风险、计划/预计完工）。
     *
     * 订单下的多产品行、拆单子任务先聚合回订单，再计算履约状态，避免
     * 把拆单子任务重复计为订单。生产完成和入库完成分别展示，避免混淆。
     */
    public function orderOverview(array $params = []): array
    {
        $orders = Order::with(['lines.tasks.schedules'])->get();
        $processProgressByTask = $this->processProgressByTask($orders);

        $items = $orders->map(fn (Order $order): array => $this->orderFulfillmentItem($order, $processProgressByTask))->values();

        $kw = trim((string) ($params['keyword'] ?? ''));
        $due = trim((string) ($params['due_flag'] ?? ''));
        $fulfillmentStatus = trim((string) ($params['fulfillment_status'] ?? ''));
        $items = $items->filter(function ($i) use ($kw, $due) {
            if ($kw && ! str_contains(strtolower($i['order_no'].' '.$i['customer_name'].' '.($i['k3_bill_no'] ?? '')), strtolower($kw))) {
                return false;
            }
            if ($due && $i['due_flag'] !== $due) {
                return false;
            }

            return true;
        })->filter(fn (array $item): bool => ! $fulfillmentStatus || $item['fulfillment_status'] === $fulfillmentStatus)
            ->sortBy(function (array $item): string {
                $rank = [
                    'overdue' => 1,
                    'forecast_late' => 2,
                    'paused' => 3,
                    'not_started' => 4,
                    'scheduled' => 5,
                    'in_progress' => 6,
                    'completed_late' => 7,
                    'completed_on_time' => 8,
                    'aborted' => 9,
                ][$item['fulfillment_status']] ?? 9;

                return sprintf('%02d-%d-%s', $rank, (int) $item['priority'], $item['delivery_date'] ?? '9999-12-31');
            })->values();

        $sumQty = fn (callable $pred) => (int) $items->filter($pred)->sum('total_qty');
        $totalQty = (int) $items->sum('total_qty');
        $productionDoneQty = (int) $items->sum('done_qty');
        $processPlanQty = (int) $items->sum('process_plan_qty');
        $processDoneQty = (int) $items->sum('process_done_qty');
        $stockedQty = (int) $items->sum('stocked_qty');
        $completed = $items->filter(fn (array $item): bool => str_starts_with($item['fulfillment_status'], 'completed'));
        $completedWithDelivery = $completed->filter(fn (array $item): bool => $item['delivery_date'] !== null);

        return [
            'summary' => [
                'total_orders' => $items->count(),
                'production_completed' => $completed->count(),
                'on_time_completed' => $items->where('fulfillment_status', 'completed_on_time')->count(),
                'late_completed' => $items->where('fulfillment_status', 'completed_late')->count(),
                'in_progress' => $items->whereIn('fulfillment_status', ['scheduled', 'in_progress'])->count(),
                'forecast_late' => $items->where('fulfillment_status', 'forecast_late')->count(),
                'overdue' => $items->where('fulfillment_status', 'overdue')->count(),
                'not_started' => $items->where('fulfillment_status', 'not_started')->count(),
                'paused' => $items->where('fulfillment_status', 'paused')->count(),
                'total_qty' => $totalQty,
                'done_qty' => $productionDoneQty,
                'process_plan_qty' => $processPlanQty,
                'process_done_qty' => $processDoneQty,
                'process_progress_pct' => $processPlanQty > 0 ? min(100, round($processDoneQty / $processPlanQty * 100, 1)) : 0,
                'stocked_qty' => $stockedQty,
                'production_completion_pct' => $totalQty > 0 ? round($productionDoneQty / $totalQty * 100, 1) : 0,
                'stock_completion_pct' => $totalQty > 0 ? round($stockedQty / $totalQty * 100, 1) : 0,
                'on_time_rate' => $completedWithDelivery->isNotEmpty()
                    ? round($completedWithDelivery->where('fulfillment_status', 'completed_on_time')->count() / $completedWithDelivery->count() * 100, 1)
                    : null,
            ],
            'groups' => [
                'pending' => ['count' => $items->where('fulfillment_status', 'not_started')->count(), 'qty' => $sumQty(fn ($i) => $i['fulfillment_status'] === 'not_started')],
                'in_progress' => ['count' => $items->whereIn('fulfillment_status', ['scheduled', 'in_progress'])->count(), 'qty' => $sumQty(fn ($i) => in_array($i['fulfillment_status'], ['scheduled', 'in_progress'], true))],
                'overdue' => ['count' => $items->where('due_flag', 'overdue')->count(), 'qty' => $sumQty(fn ($i) => $i['due_flag'] === 'overdue')],
                'done' => ['count' => $completed->count(), 'qty' => $sumQty(fn ($i) => str_starts_with($i['fulfillment_status'], 'completed'))],
            ],
            'items' => $items->all(),
        ];
    }

    /** 订单头聚合：多产品行、拆单子任务均归并后再计算履约状态。 */
    private function orderFulfillmentItem(Order $order, array $processProgressByTask): array
    {
        $lines = $order->lines;
        $tasks = $lines->flatMap(fn ($line) => $line->tasks ?? collect());
        $schedules = $tasks->flatMap(fn ($task) => $task->schedules ?? collect());
        $totalQty = (int) $lines->sum('qty');
        $doneQty = min($totalQty, max(0, (int) ($tasks->isNotEmpty() ? $tasks->sum('done_qty') : $lines->sum('done_qty'))));
        $processProgress = $this->orderProcessProgress($tasks, $lines, $totalQty, $processProgressByTask);
        $stockedQty = min($totalQty, max(0, (int) $tasks->sum('stocked_qty')));
        $deliveryDate = collect([$order->delivery_date])
            ->merge($lines->pluck('delivery_date'))
            ->filter()
            ->sortBy(fn ($date) => $date->timestamp)
            ->first();
        $planStart = $tasks->pluck('plan_start')->filter()->sortBy(fn ($date) => $date->timestamp)->first();
        $planEnd = $tasks->pluck('plan_end')->filter()->sortBy(fn ($date) => $date->timestamp)->last();
        $forecastEnd = $schedules->map(fn ($schedule) => $schedule->forecast_end ?? $schedule->plan_end)
            ->filter()
            ->sortBy(fn ($date) => $date->timestamp)
            ->last() ?? $planEnd;
        $actualEnd = $schedules->pluck('actual_end')->filter()->sortBy(fn ($date) => $date->timestamp)->last();
        $completionAt = $order->completed_at ?? $actualEnd;
        $hasSchedule = $tasks->contains(fn ($task) => $task->plan_start !== null || ($task->schedules?->isNotEmpty() ?? false));
        $hasStarted = $processProgress['process_done_qty'] > 0 || $doneQty > 0 || $tasks->contains(fn ($task) => $task->status === ProdTask::STATUS_IN_PROGRESS);
        $isPaused = $order->status === Order::STATUS_PAUSED || $tasks->contains(fn ($task) => $task->status === ProdTask::STATUS_PAUSED);
        $isCompleted = $totalQty > 0 && ($doneQty >= $totalQty || $order->status === Order::STATUS_PRODUCED);
        $today = now()->startOfDay();
        $due = $deliveryDate?->copy()->startOfDay();
        $completionDay = $completionAt?->copy()->startOfDay();
        $isLateCompleted = $isCompleted && $due && $completionDay && $completionDay->gt($due);
        $isOverdue = ! $isCompleted && $due && $today->gt($due);
        $isForecastLate = ! $isCompleted && ! $isOverdue && $due && $forecastEnd && $forecastEnd->copy()->startOfDay()->gt($due);

        $fulfillmentStatus = match (true) {
            $order->status === Order::STATUS_ABORTED => 'aborted',
            $isLateCompleted => 'completed_late',
            $isCompleted => 'completed_on_time',
            $isPaused => 'paused',
            $isOverdue => 'overdue',
            $isForecastLate => 'forecast_late',
            ! $hasSchedule && ! $hasStarted => 'not_started',
            $tasks->contains(fn ($task) => $task->status === ProdTask::STATUS_SCHEDULED) && ! $hasStarted => 'scheduled',
            default => 'in_progress',
        };

        $comparisonDate = str_starts_with($fulfillmentStatus, 'completed')
            ? $completionDay
            : ($forecastEnd?->copy()->startOfDay() ?? $today);
        $delayDays = $due && $comparisonDate && $comparisonDate->gt($due) ? (int) $due->diffInDays($comparisonDate) : 0;
        $dueFlag = ! $isCompleted && $due
            ? ($today->gt($due) ? 'overdue' : ($today->diffInDays($due) <= 3 ? 'due_soon' : 'normal'))
            : 'normal';
        $labels = [
            'completed_on_time' => '按期完成',
            'completed_late' => '延期完成',
            'overdue' => '已延期',
            'forecast_late' => '预计延期',
            'paused' => '已暂停',
            'not_started' => '未开工',
            'scheduled' => '已排产',
            'in_progress' => '生产中',
            'aborted' => '已中止',
        ];
        $taskStatus = $isCompleted ? 'done' : ($fulfillmentStatus === 'not_started' ? 'unscheduled' : ($fulfillmentStatus === 'scheduled' ? 'scheduled' : 'in_progress'));

        return [
            'order_id' => (int) $order->id,
            'order_no' => $order->order_no,
            'k3_bill_no' => $order->k3_bill_no,
            'customer_name' => $order->customer_name,
            'priority' => (int) $order->priority,
            'delivery_date' => $deliveryDate?->toDateString(),
            'status' => $order->status,
            'status_label' => $order->status_label,
            'total_qty' => $totalQty,
            'done_qty' => $doneQty,
            'process_plan_qty' => $processProgress['process_plan_qty'],
            'process_done_qty' => $processProgress['process_done_qty'],
            'process_progress_pct' => $processProgress['process_progress_pct'],
            'stocked_qty' => $stockedQty,
            'progress_pct' => $totalQty ? (int) round($doneQty / $totalQty * 100) : 0,
            'stock_progress_pct' => $totalQty ? (int) round($stockedQty / $totalQty * 100) : 0,
            'due_flag' => $dueFlag,
            'delay_days' => $delayDays,
            'fulfillment_status' => $fulfillmentStatus,
            'fulfillment_status_label' => $labels[$fulfillmentStatus] ?? $fulfillmentStatus,
            'tasks_count' => $tasks->count(),
            'completed_tasks' => $tasks->where('status', ProdTask::STATUS_DONE)->count(),
            'paused_tasks' => $tasks->where('status', ProdTask::STATUS_PAUSED)->count(),
            'task_status' => $taskStatus,
            'plan_start' => $planStart?->format('Y-m-d H:i'),
            'plan_end' => $planEnd?->format('Y-m-d H:i'),
            'forecast_end' => $forecastEnd?->format('Y-m-d H:i'),
            'actual_end' => $actualEnd?->format('Y-m-d H:i'),
        ];
    }

    /** 按 task_id + op_type 一次性聚合工序计划量和报工净量，避免逐订单查询报工明细。 */
    private function processProgressByTask(Collection $orders): array
    {
        $taskIds = $orders->flatMap(fn (Order $order) => $order->lines->flatMap(fn ($line) => $line->tasks ?? collect()))
            ->pluck('id')->unique()->values()->all();
        if ($taskIds === []) {
            return [];
        }

        $plans = DB::table('task_schedules')
            ->whereIn('task_id', $taskIds)
            ->select('task_id', 'op_type', DB::raw('SUM(qty) AS plan_qty'))
            ->groupBy('task_id', 'op_type')
            ->get();
        $reports = DB::table('task_schedules')
            ->join('work_reports', 'work_reports.schedule_id', '=', 'task_schedules.id')
            ->whereIn('task_schedules.task_id', $taskIds)
            ->select('task_schedules.task_id', 'task_schedules.op_type', DB::raw('SUM(work_reports.qty) AS done_qty'))
            ->groupBy('task_schedules.task_id', 'task_schedules.op_type')
            ->get()
            ->keyBy(fn ($row) => $row->task_id.'|'.$row->op_type);

        $progress = [];
        foreach ($plans as $stage) {
            $taskId = (int) $stage->task_id;
            $key = $taskId.'|'.$stage->op_type;
            $progress[$taskId] ??= ['process_plan_qty' => 0, 'process_done_qty' => 0];
            $progress[$taskId]['process_plan_qty'] += (int) $stage->plan_qty;
            $progress[$taskId]['process_done_qty'] += max(0, (int) ($reports->get($key)?->done_qty ?? 0));
        }

        return $progress;
    }

    /**
     * 汇总订单下各任务的工序推进工作量；无生产任务时兼容订单行完成量。
     *
     * @param  array<int, array{process_plan_qty:int, process_done_qty:int}>  $progressByTask
     * @return array{process_plan_qty:int, process_done_qty:int, process_progress_pct:float|int}
     */
    private function orderProcessProgress(Collection $tasks, Collection $lines, int $totalQty, array $progressByTask): array
    {
        $planQty = 0;
        $doneQty = 0;
        foreach ($tasks as $task) {
            $progress = $progressByTask[$task->id] ?? null;
            if ($progress === null) {
                continue;
            }

            $planQty += $progress['process_plan_qty'];
            $doneQty += $progress['process_done_qty'];
        }

        // 兼容历史上没有拆出生产任务、只在订单产品行上记完成量的数据。
        if ($tasks->isEmpty()) {
            $planQty = $totalQty;
            $doneQty = max(0, (int) $lines->sum('done_qty'));
        }

        return [
            'process_plan_qty' => $planQty,
            'process_done_qty' => $doneQty,
            'process_progress_pct' => $planQty > 0 ? min(100, round($doneQty / $planQty * 100, 1)) : 0,
        ];
    }

    /**
     * 任务执行看板
     */
    public function taskBoard(): array
    {
        $tasks = ProdTask::with(['product', 'schedules.machine'])->get();

        return [
            'overview' => [
                'total' => $tasks->count(),
                'unscheduled' => $tasks->where('status', ProdTask::STATUS_UNSCHEDULED)->count(),
                'scheduled' => $tasks->where('status', ProdTask::STATUS_SCHEDULED)->count(),
                'in_progress' => $tasks->whereIn('status', [ProdTask::STATUS_IN_PROGRESS, ProdTask::STATUS_PAUSED])->count(),
                'done' => $tasks->where('status', ProdTask::STATUS_DONE)->count(),
            ],
            'items' => $tasks->map(function (ProdTask $t) {
                $first = $t->schedules->first();

                return [
                    'id' => (int) $t->id,
                    'task_no' => $t->task_no,
                    'product_code' => $t->product?->code,
                    'product_name' => $t->product?->name,
                    'machine_code' => $first?->machine?->code,
                    'status' => $t->status,
                    'qty' => (int) $t->qty,
                    'done_qty' => (int) $t->done_qty,
                    'progress_pct' => $t->qty ? (int) round(($t->done_qty / $t->qty) * 100) : 0,
                    'priority' => (int) $t->priority,
                    'delivery_date' => $t->delivery_date?->toDateString(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * 产能负荷热力：task_schedules 按 机台 × 未来 7 天 汇总负荷
     */
    public function loadHeatmap(): array
    {
        $machines = Machine::orderBy('id')->get();
        $days = collect(range(0, 6))->map(fn ($d) => now()->addDays($d)->toDateString());

        $rows = $machines->map(function (Machine $m) use ($days) {
            $cells = $days->map(function (string $date) use ($m) {
                $dayStart = now()->parse($date)->startOfDay();
                $dayEnd = $dayStart->copy()->endOfDay();
                // 负荷分母必须跟排产使用同一套班次配置，不能固定写死 9/24 小时。
                // 停用/维修资源不计入可用产能，避免把维护期误判成空闲产能。
                $availableHours = in_array($m->status, [Machine::STATUS_STOPPED, Machine::STATUS_MAINTENANCE], true)
                    ? 0.0
                    : $this->availableHoursFor($m, $dayStart);

                $schedules = TaskSchedule::where('machine_id', $m->id)
                    ->whereNotNull('plan_start')
                    ->whereNotNull('plan_end')
                    ->get();

                $hours = 0.0;
                foreach ($schedules as $s) {
                    $ps = $s->plan_start;
                    $pe = $s->plan_end;
                    $overlapStart = $ps->greaterThan($dayStart) ? $ps : $dayStart;
                    $overlapEnd = $pe->lessThan($dayEnd) ? $pe : $dayEnd;
                    if ($overlapStart->lessThan($overlapEnd)) {
                        $hours += $overlapStart->diffInMinutes($overlapEnd) / 60;
                    }
                }

                $load = $availableHours > 0 ? min(100, round(($hours / $availableHours) * 100)) : null;

                return [
                    'date' => $date,
                    'load_pct' => $load,
                    'alert' => $load !== null && $load > (int) config('dashboard.load_alert_pct', 85),
                    'machine_id' => (int) $m->id,
                ];
            })->values();

            return [
                'machine_id' => (int) $m->id,
                'machine_code' => $m->code,
                'machine_name' => $m->name,
                'workshop' => $m->workshop,
                'cells' => $cells->all(),
                'avg_load' => (int) round($cells->pluck('load_pct')->filter(fn ($value) => $value !== null)->avg() ?? 0),
            ];
        })->values();

        $alertCount = $rows->filter(fn ($r) => collect($r['cells'])->contains('alert', true))->count();
        $avgAll = $rows->avg('avg_load') ?? 0;
        $loadValues = $rows->flatMap(fn ($r) => collect($r['cells'])->pluck('load_pct'))
            ->filter(fn ($value) => $value !== null)
            ->values()
            ->all();
        $peak = $loadValues === [] ? 0 : max($loadValues);

        return [
            'start' => $days->first(),
            'end' => $days->last(),
            'days' => $days->all(),
            'rows' => $rows->all(),
            'alert_threshold' => (int) config('dashboard.load_alert_pct', 85),
            'summary' => [
                'alert_count' => $alertCount,
                'avg_load' => (int) round($avgAll),
                'peak_load' => (int) $peak,
            ],
        ];
    }

    /**
     * 读取排产同源的班次配置，计算指定资源某日的可用小时数。
     */
    private function availableHoursFor(Machine $machine, Carbon $date): float
    {
        $periods = ConfigService::shiftPeriods($machine->shift_mode ?: 'two_shift');
        $windows = $machine->shift_mode === 'day'
            ? [$periods]
            : array_values(array_filter($periods, 'is_array'));
        $minutes = 0;

        foreach ($windows as $window) {
            if (! isset($window['start'], $window['end'])) {
                continue;
            }

            $start = $date->copy()->setTimeFromTimeString((string) $window['start']);
            $end = $date->copy()->setTimeFromTimeString((string) $window['end']);
            if ($end->lte($start)) {
                $end->addDay();
            }
            $minutes += $start->diffInMinutes($end);
        }

        return $minutes / 60;
    }

    /**
     * 报表中心（交期达成率/机台利用率/报工及时率/次品率/模具寿命）
     */
    public function reports(): array
    {
        $orders = Order::all();
        $produced = $orders->where('status', Order::STATUS_PRODUCED)->count();
        $overdueOpen = $orders->filter(fn (Order $o) => $o->status !== Order::STATUS_PRODUCED && $o->delivery_date && $o->delivery_date->lt(now()))->count();
        $deliveryRate = ($produced + $overdueOpen) > 0 ? (int) round(($produced / ($produced + $overdueOpen)) * 100) : 100;

        $util = (int) round($this->loadHeatmap()['summary']['avg_load'] ?? 0);

        $tasks = ProdTask::with('reports')->whereNotNull('plan_start')->get();
        $timelyTasks = $tasks->filter(function (ProdTask $t) {
            $first = $t->reports()->orderBy('report_time')->first();
            if (! $first) {
                return false;
            }
            if (! $t->plan_start) {
                return true;
            }

            return $first->report_time->lte($t->plan_start->copy()->addHours((float) config('dashboard.no_report_hours', 3)));
        })->count();
        $reportTimely = $tasks->count() ? (int) round(($timelyTasks / $tasks->count()) * 100) : 100;

        // 报工流水包含冲减负记录，指标按净值计算，避免历史更正后仍显示旧的次品率。
        $totalQty = (int) WorkReport::sum('qty');
        $defectQty = (int) WorkReport::sum('defect_qty');
        $defectBase = $totalQty + $defectQty;
        $defectRate = $defectBase > 0 ? round(($defectQty / $defectBase) * 100, 1) : 0;

        $molds = Mold::all();
        $moldLife = $molds
            ->filter(fn (Mold $m) => $m->lifetime_openings > 0)
            ->map(fn (Mold $m) => [
                'mold_id' => (int) $m->id,
                'mold_code' => $m->code,
                'mold_name' => $m->name,
                'life_used_pct' => round(($m->opened_count / $m->lifetime_openings) * 100, 1),
                'maintain_due' => $m->next_maintain_at ? $m->next_maintain_at->lte(now()) : false,
            ])
            ->sortByDesc('life_used_pct')
            ->take(5)
            ->values()
            ->all();
        $moldLifeAvg = $molds->filter(fn (Mold $m) => $m->lifetime_openings > 0)->count()
            ? round($molds->filter(fn (Mold $m) => $m->lifetime_openings > 0)->avg(fn (Mold $m) => ($m->opened_count / $m->lifetime_openings) * 100), 1)
            : 0;

        return [
            'metrics' => [
                ['key' => 'delivery_rate', 'label' => '交期达成率', 'value' => $deliveryRate, 'unit' => '%', 'trend' => 'up', 'compare_text' => '实时口径'],
                ['key' => 'utilization', 'label' => '机台利用率', 'value' => $util, 'unit' => '%', 'trend' => 'up', 'compare_text' => '未来 7 天负荷'],
                ['key' => 'report_timely', 'label' => '报工及时率', 'value' => $reportTimely, 'unit' => '%', 'trend' => 'flat', 'compare_text' => '首报 ≤ 计划起点+N 小时'],
                ['key' => 'defect_rate', 'label' => '综合次品率', 'value' => $defectRate, 'unit' => '%', 'trend' => 'down', 'compare_text' => '合格口径'],
                ['key' => 'overdue_orders', 'label' => '逾期订单', 'value' => $overdueOpen, 'unit' => '单', 'trend' => 'down', 'compare_text' => '未完工且已逾期'],
                ['key' => 'mold_life_avg', 'label' => '模具寿命平均消耗', 'value' => $moldLifeAvg, 'unit' => '%', 'trend' => 'up', 'compare_text' => '开模数/设计寿命'],
            ],
            // 趋势全部从业务流水实时聚合；没有样本时返回 null，前端展示「暂无数据」，
            // 不再用波形数据填充页面，避免把演示值误认为生产指标。
            'delivery_series' => $this->deliverySeries(7),
            'utilization_series' => $this->utilizationSeries(7),
            'report_timely_series' => $this->reportTimelySeries(4),
            'defect_series' => $this->defectSeries(7),
            'mold_life' => $moldLife,
        ];
    }

    /**
     * 临期订单（3 天内交付未完成）—— 企微消息 D8 推送责任人
     */
    public function dueSoonOrders(): array
    {
        $orders = Order::with('lines')->get();

        return $orders
            ->filter(fn (Order $o) => $o->status !== Order::STATUS_PRODUCED
                && $o->delivery_date
                && $o->delivery_date->gt(now())
                && $o->delivery_date->lte(now()->addDays(3)))
            ->sortBy('delivery_date')
            ->values()
            ->map(fn (Order $o) => [
                'order_id' => (int) $o->id,
                'order_no' => $o->order_no,
                'customer_name' => $o->customer_name,
                'delivery_date' => $o->delivery_date?->toDateString(),
                'days_left' => max(0, (int) now()->startOfDay()->diffInDays($o->delivery_date->copy()->startOfDay())),
                'progress_pct' => $this->orderProgress($o),
            ])
            ->all();
    }

    // ==================== 工具 ====================

    private function deliverySeries(int $days): array
    {
        $data = [];
        $today = now()->startOfDay();
        $orders = Order::query()->get();

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i);
            $due = $orders->filter(fn (Order $order) => $order->delivery_date?->isSameDay($date));
            $onTime = $due->filter(fn (Order $order) => $order->status === Order::STATUS_PRODUCED
                && $order->completed_at
                && $order->completed_at->lte($date->copy()->endOfDay()));

            $data[] = [
                'date' => $date->toDateString(),
                'value' => $due->isEmpty() ? null : round($onTime->count() / $due->count() * 100, 1),
            ];
        }

        return ['label' => '交期达成率（近 7 天）', 'data' => $data];
    }

    private function utilizationSeries(int $days): array
    {
        $heatmap = $this->loadHeatmap();
        $data = collect($heatmap['days'])->map(function (string $date, int $index) use ($heatmap): array {
            $values = collect($heatmap['rows'])
                ->map(fn (array $row) => $row['cells'][$index]['load_pct'] ?? null)
                ->filter(fn ($value) => $value !== null);

            return [
                'date' => $date,
                'value' => $values->isEmpty() ? null : round($values->avg(), 1),
            ];
        })->values()->all();

        return ['label' => '机台利用率（未来 7 天计划负荷）', 'data' => $data];
    }

    private function reportTimelySeries(int $weeks): array
    {
        $tasks = ProdTask::with('reports')->whereNotNull('plan_start')->get();
        $currentWeek = now()->startOfWeek();
        $data = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = $currentWeek->copy()->subWeeks($i);
            $end = $start->copy()->endOfWeek();
            $weekTasks = $tasks->filter(fn (ProdTask $task) => $task->plan_start?->betweenIncluded($start, $end));
            $timely = $weekTasks->filter(function (ProdTask $task): bool {
                $first = $task->reports->sortBy('report_time')->first();
                if (! $first) {
                    return false;
                }

                return $first->report_time->lte($task->plan_start->copy()->addHours((float) config('dashboard.no_report_hours', 3)));
            });

            $data[] = [
                'date' => $start->format('Y-m-d').' ~ '.$end->format('m-d'),
                'value' => $weekTasks->isEmpty() ? null : round($timely->count() / $weekTasks->count() * 100, 1),
            ];
        }

        return ['label' => '报工及时率（近 4 周）', 'data' => $data];
    }

    private function defectSeries(int $days): array
    {
        $today = now()->startOfDay();
        $data = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i);
            $reports = WorkReport::query()->whereDate('work_date', $date->toDateString())->get();
            $qualified = (int) $reports->sum('qty');
            $defect = (int) $reports->sum('defect_qty');
            $base = $qualified + $defect;

            $data[] = [
                'date' => $date->toDateString(),
                'value' => $base > 0 ? round($defect / $base * 100, 1) : null,
            ];
        }

        return ['label' => '次品率（近 7 天）', 'data' => $data];
    }

    private function orderProgress(Order $order): int
    {
        $total = (int) $order->lines()->sum('qty');
        $done = (int) $order->lines()->sum('done_qty');

        return $total ? (int) round(($done / $total) * 100) : 0;
    }

    private function dueFlag(Order $order): string
    {
        if ($order->status === Order::STATUS_PRODUCED || ! $order->delivery_date) {
            return 'normal';
        }
        $diff = now()->startOfDay()->diffInDays($order->delivery_date->copy()->startOfDay(), false);
        if ($diff < 0) {
            return 'overdue';
        }
        if ($diff <= 3) {
            return 'due_soon';
        }

        return 'normal';
    }
}
