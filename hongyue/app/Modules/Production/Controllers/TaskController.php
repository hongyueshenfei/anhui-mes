<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\ProdTask;
use App\Models\TaskSchedule;
use App\Models\WorkReport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 任务控制器（PC /api/tasks + 移动端 /mobile/tasks）
 *
 * GET /api/tasks               任务列表（状态/机台过滤）
 * GET /api/tasks/{id}          任务详情（含排程/报工汇总）
 * GET /api/tasks/{id}/report-history  报工流水
 */
class TaskController extends Controller
{
    /**
     * 任务列表（C2 今日任务数据源）
     */
    public function index(Request $request): JsonResponse
    {
        $query = ProdTask::query()
            ->with(['product:id,code,name,spec,unit', 'mold:id,code,name', 'line.order:id,order_no', 'schedules.machine:id,code,name'])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('machine_id'), fn ($q, $v) => $q->whereHas('schedules', fn ($w) => $w->where('machine_id', (int) $v)))
            ->when($request->input('worker_id'), fn ($q, $v) => $q->whereExists(
                fn ($w) => $w->selectRaw('1')->from('work_reports')
                    ->whereColumn('work_reports.task_id', 'prod_tasks.id')
                    ->where('work_reports.worker_id', (int) $v)
            ))
            ->when($request->input('date'), function ($q, $date) {
                $day = Carbon::parse($date);
                $q->where(function ($w) use ($day) {
                    $w->whereDate('plan_start', '<=', $day->copy()->endOfDay())
                        ->whereDate('plan_end', '>=', $day->copy()->startOfDay());
                });
            })
            ->when($request->input('keyword'), function ($q, $kw) {
                $q->where(fn ($w) => $w->where('task_no', 'like', "%{$kw}%")
                    ->orWhereHas('product', fn ($p) => $p->where('code', 'like', "%{$kw}%")->orWhere('name', 'like', "%{$kw}%")));
            })
            // 状态优先排序（CASE 写法兼容 SQLite/MySQL）
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 1 WHEN 'scheduled' THEN 2 WHEN 'paused' THEN 3 WHEN 'unscheduled' THEN 4 ELSE 9 END, plan_start IS NULL, plan_start ASC");

        $perPage = min((int) $request->input('per_page', 15), 100);

        if ($request->boolean('all')) {
            return $this->ok($query->limit(500)->get());
        }

        return $this->ok($query->paginate($perPage));
    }

    /**
     * PC 端当日报工表数据。
     *
     * 一行对应一条已接受的工序排程，计划字段来自 task_schedules，累计报工来自
     * work_reports 聚合；前端只负责录入本次数量，不复制或改写订单/排程数据。
     */
    public function dailyReportContext(Request $request): JsonResponse
    {
        $date = $request->input('work_date', now()->toDateString());
        try {
            $workDate = Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay();
        } catch (\Throwable) {
            return response()->json(['code' => 422, 'message' => '报工日期格式应为 YYYY-MM-DD', 'data' => null], 422);
        }
        if ($workDate->isAfter(now()->startOfDay())) {
            return response()->json(['code' => 422, 'message' => '不能提前录入未来日期的报工', 'data' => null], 422);
        }

        $schedules = TaskSchedule::with([
            'task:id,task_no,order_line_id,product_id,qty,status,delivery_date',
            'task.product:id,code,name',
            'task.line.order:id,order_no',
            'machine:id,code,name,type,workshop_id',
            'machine.workshop:id,name',
        ])
            ->where('is_interrupted', 0)
            ->whereNotNull('machine_id')
            ->where('plan_start', '<', $workDate->copy()->endOfDay())
            ->where('plan_end', '>', $workDate)
            ->when($request->input('machine_id'), fn ($q, $v) => $q->where('machine_id', (int) $v))
            ->when($request->input('workshop_id'), fn ($q, $v) => $q->whereHas('machine', fn ($m) => $m->where('workshop_id', (int) $v)))
            ->when($request->input('shift_code') === 'day' || $request->input('shift_code') === 'night', function ($q) use ($request, $workDate) {
                $shift = $request->input('shift_code') === 'night';
                $start = $shift ? $workDate->copy()->addHours(20) : $workDate->copy()->addHours(8);
                $end = $shift ? $workDate->copy()->addDay()->addHours(8) : $workDate->copy()->addHours(20);
                $q->where('plan_start', '<', $end)->where('plan_end', '>', $start);
            })
            ->when($request->input('keyword'), function ($q, $kw) {
                $q->where(fn ($w) => $w->whereHas('task', fn ($t) => $t->where('task_no', 'like', "%{$kw}%"))
                    ->orWhereHas('task.product', fn ($p) => $p->where('code', 'like', "%{$kw}%")->orWhere('name', 'like', "%{$kw}%"))
                    ->orWhereHas('task.line.order', fn ($o) => $o->where('order_no', 'like', "%{$kw}%")));
            })
            ->orderBy('machine_id')
            ->orderBy('plan_start')
            ->orderBy('seq')
            ->get();

        $reported = WorkReport::query()
            ->whereIn('schedule_id', $schedules->pluck('id'))
            ->selectRaw('schedule_id, SUM(qty) AS reported_qty, SUM(defect_qty) AS defect_qty')
            ->groupBy('schedule_id')
            ->get()
            ->keyBy('schedule_id');

        // 任务级成品报工没有 schedule_id，但仍要在 PC 日结表体现；
        // 统一挂到该任务最后一道未打断工序，避免同一笔成品数量重复计入每道工序。
        $taskLevelReported = WorkReport::query()
            ->whereIn('task_id', $schedules->pluck('task_id')->unique())
            ->whereNull('schedule_id')
            ->selectRaw('task_id, SUM(qty) AS reported_qty, SUM(defect_qty) AS defect_qty')
            ->groupBy('task_id')
            ->get()
            ->keyBy('task_id');
        // 任务级成品报工只能挂到任务全量排程中的最后一道未打断工序；
        // 不能按当天可见排程的最后一行判断，否则下游工序在其他日期时会
        // 把成品数量错误显示到当天的前序工序。
        $lastScheduleIds = TaskSchedule::whereIn('task_id', $schedules->pluck('task_id')->unique())
            ->where('is_interrupted', 0)
            ->get(['id', 'task_id', 'seq'])
            ->groupBy('task_id')
            ->map(fn ($rows) => $rows->sortBy([['seq', 'desc'], ['id', 'desc']])->first()?->id);

        $rows = $schedules->map(function (TaskSchedule $schedule) use ($reported, $taskLevelReported, $lastScheduleIds, $workDate) {
            $summary = $reported->get($schedule->id);
            $taskSummary = (int) $lastScheduleIds->get($schedule->task_id) === (int) $schedule->id
                ? $taskLevelReported->get($schedule->task_id)
                : null;
            $reportedQty = max(0, (int) ($summary?->reported_qty ?? 0) + (int) ($taskSummary?->reported_qty ?? 0));
            $defectQty = (int) ($summary?->defect_qty ?? 0) + (int) ($taskSummary?->defect_qty ?? 0);
            $plannedQty = (int) $schedule->qty;
            $task = $schedule->task;

            return [
                'schedule_id' => $schedule->id,
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'order_no' => $task->line?->order?->order_no,
                'product' => $task->product ? ['id' => $task->product->id, 'code' => $task->product->code, 'name' => $task->product->name] : null,
                'operation' => ['seq' => $schedule->seq, 'name' => $schedule->op_name ?? $schedule->op_type],
                'machine' => $schedule->machine ? [
                    'id' => $schedule->machine->id,
                    'code' => $schedule->machine->code,
                    'name' => $schedule->machine->name,
                    'workshop_id' => $schedule->machine->workshop_id,
                    'workshop_name' => $schedule->machine->workshop?->name,
                ] : null,
                'plan_start' => $schedule->plan_start?->format('Y-m-d H:i:s'),
                'plan_end' => $schedule->plan_end?->format('Y-m-d H:i:s'),
                'planned_qty' => $plannedQty,
                'reported_qty' => $reportedQty,
                'defect_qty' => $defectQty,
                'remaining_qty' => max(0, $plannedQty - $reportedQty),
                'schedule_status' => $schedule->status,
                'task_status' => $task->status,
                'delivery_date' => $task->delivery_date?->format('Y-m-d H:i:s'),
                'work_date' => $workDate->toDateString(),
            ];
        })->values();

        return $this->ok([
            'work_date' => $workDate->toDateString(),
            'rows' => $rows,
            'summary' => [
                'row_count' => $rows->count(),
                'planned_qty' => (int) $rows->sum('planned_qty'),
                'reported_qty' => (int) $rows->sum('reported_qty'),
                'remaining_qty' => (int) $rows->sum('remaining_qty'),
            ],
        ]);
    }

    /**
     * 机台扫码后的日任务上下文。
     *
     * 任务来源只取已保存的 task_schedules 计划快照，并按计划时间与工作日相交筛选；
     * 因此同一机台当天的任务集合是确定的，现场只需在集合中选择当前任务，不再自由选任意工单。
     */
    public function machineReportContext(Request $request, string $code): JsonResponse
    {
        $machine = Machine::with('workshop')->where('code', $code)->first();
        if (! $machine) {
            return response()->json(['code' => 404, 'message' => '机台不存在或已停用', 'data' => null], 404);
        }
        if (! $machine->is_active || $machine->status !== Machine::STATUS_RUNNING) {
            return response()->json([
                'code' => 409,
                'message' => "机台 {$machine->code} 当前状态为 {$machine->status_label}，不可进入报工",
                'data' => [
                    'error_code' => 'machine_unavailable',
                    'machine_id' => $machine->id,
                    'machine_code' => $machine->code,
                    'machine_status' => $machine->status,
                ],
            ], 409);
        }

        $date = $request->input('date', now()->toDateString());
        try {
            $workDate = Carbon::createFromFormat('Y-m-d', (string) $date)->startOfDay();
        } catch (\Throwable) {
            return response()->json(['code' => 422, 'message' => '工作日期格式应为 YYYY-MM-DD', 'data' => null], 422);
        }
        if ($workDate->isAfter(now()->startOfDay())) {
            return response()->json(['code' => 422, 'message' => '不能提前查看未来日期的机台报工任务', 'data' => null], 422);
        }

        $schedules = TaskSchedule::with([
            'task:id,task_no,order_line_id,product_id,qty,status,delivery_date',
            'task.product:id,code,name',
            'task.line.order:id,order_no',
            'machine:id,code,name,type,workshop_id',
        ])
            ->where('machine_id', $machine->id)
            ->where('is_interrupted', 0)
            ->where('plan_start', '<', $workDate->copy()->endOfDay())
            ->where('plan_end', '>', $workDate)
            ->orderBy('plan_start')
            ->orderBy('seq')
            ->get();

        $reported = WorkReport::query()
            ->whereIn('schedule_id', $schedules->pluck('id'))
            ->selectRaw('schedule_id, SUM(qty) AS reported_qty, SUM(defect_qty) AS defect_qty')
            ->groupBy('schedule_id')
            ->get()
            ->keyBy('schedule_id');

        $operator = $request->user();
        $canReport = $operator && (in_array($operator->job_type, [
            \App\Models\User::JOB_ADMIN,
            \App\Models\User::JOB_LEADER,
            \App\Models\User::JOB_STATISTICIAN,
        ], true) || $operator->hasAnyRole(['admin', 'leader', 'statistician']));

        $tasks = $schedules->map(function (TaskSchedule $schedule) use ($reported, $canReport) {
            $summary = $reported->get($schedule->id);
            $reportedQty = max(0, (int) ($summary?->reported_qty ?? 0));
            $remainingQty = max(0, (int) $schedule->qty - $reportedQty);
            $task = $schedule->task;

            return [
                'schedule_id' => $schedule->id,
                'task_id' => $task->id,
                'task_no' => $task->task_no,
                'order_no' => $task->line?->order?->order_no,
                'product' => $task->product ? [
                    'id' => $task->product->id,
                    'code' => $task->product->code,
                    'name' => $task->product->name,
                ] : null,
                'operation' => ['seq' => $schedule->seq, 'name' => $schedule->op_name ?? $schedule->op_type],
                'plan_start' => $schedule->plan_start?->format('Y-m-d H:i:s'),
                'plan_end' => $schedule->plan_end?->format('Y-m-d H:i:s'),
                'planned_qty' => (int) $schedule->qty,
                'reported_qty' => $reportedQty,
                'defect_qty' => (int) ($summary?->defect_qty ?? 0),
                'remaining_qty' => $remainingQty,
                'status' => $schedule->status,
                'task_status' => $task->status,
                'can_report' => $canReport && $remainingQty > 0 && $task->status !== ProdTask::STATUS_UNSCHEDULED,
            ];
        })->values();

        $shiftOptions = $machine->shift_mode === 'two_shift'
            ? [['code' => 'day', 'name' => '白班'], ['code' => 'night', 'name' => '夜班']]
            : [['code' => 'day', 'name' => '白班']];
        $defaultShift = $machine->shift_mode === 'two_shift' && now()->hour >= 20 ? 'night' : 'day';
        return $this->ok([
            'machine' => [
                'id' => $machine->id,
                'code' => $machine->code,
                'name' => $machine->name,
                'type' => $machine->type,
                'workshop_id' => $machine->workshop_id,
                'workshop_name' => $machine->workshop?->name,
            ],
            'work_date' => $workDate->toDateString(),
            'shift_options' => $shiftOptions,
            'default_shift' => $defaultShift,
            'tasks' => $tasks,
        ]);
    }

    /**
     * 任务详情
     */
    public function show(int $id): JsonResponse
    {
        $task = ProdTask::with([
            'product', 'mold', 'line.order:id,order_no,customer_name',
            'schedules.machine:id,code,name',
        ])->findOrFail($id);

        // 每道工序报工累计完工量（手动报工工序选择展示「完工」）
        $task->schedules->each(function ($s) {
            $s->setAttribute('reported_qty', max(0, (int) \App\Models\WorkReport::where('task_id', $s->task_id)
                ->where('schedule_id', $s->id)
                ->sum('qty')));
        });

        // 任务详情的完成量必须与 TaskStatusService/入库口径一致：只统计任务级报工
        // 和最后一道工序的全部排程段，不能把成型、喷漆等中间工序数量叠加成成品量。
        $lastSeq = $task->schedules->max('seq');
        $lastScheduleIds = $lastSeq === null
            ? collect()
            : $task->schedules->where('seq', $lastSeq)->pluck('id');
        $finishedReports = $task->reports()->where(function ($query) use ($lastScheduleIds) {
            $query->whereNull('schedule_id');
            if ($lastScheduleIds->isNotEmpty()) {
                $query->orWhereIn('schedule_id', $lastScheduleIds);
            }
        });

        $task->setAttribute('report_summary', [
            'done_qty' => max(0, (int) $finishedReports->sum(\Illuminate\Support\Facades\DB::raw('qty'))),
            'defect_qty' => (int) $task->reports()->sum(\Illuminate\Support\Facades\DB::raw('defect_qty')),
            'stocked_qty' => (int) ($task->stocked_qty ?? 0),
            'report_count' => $task->reports()->count(),
        ]);

        return $this->ok($task);
    }

    /**
     * 报工流水（任务维度）
     */
    public function reportHistory(int $id): JsonResponse
    {
        $task = ProdTask::findOrFail($id);

        return $this->ok($task->reports()->with('worker:id,name,employee_no', 'operator:id,name', 'machine:id,code')->get());
    }
}
