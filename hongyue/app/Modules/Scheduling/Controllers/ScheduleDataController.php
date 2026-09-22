<?php

namespace App\Modules\Scheduling\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Services\GanttDataService;
use App\Modules\Scheduling\Services\InsertScheduleService;
use App\Modules\Scheduling\Services\ParamBackCalcService;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 排产数据控制器（架构 §4：甘特数据源）
 *
 * GET /api/schedule/task-pool —— 待排任务池
 * GET /api/schedule/timeline —— 机台/班组 × 时间 甘特数据
 * GET /api/schedule/details —— 已排产任务明细列表（支持任务号筛选）
 * GET /api/schedule/orders-affected —— 插单受影响订单延期清单（纯计算预览）
 * GET /api/schedule/detail —— 任务排程明细（甘特+明细双视图）
 * GET /api/schedule/param-backcalc —— APS 参数反推建议（产能/换模，只建议不落参数）
 */
class ScheduleDataController extends Controller
{
    public function __construct(
        private readonly GanttDataService $ganttDataService,
        private readonly InsertScheduleService $insertService,
        private readonly ParamBackCalcService $backCalcService,
    ) {
    }

    /**
     * APS 参数反推建议（产能/换模）：基于实际报工数据反推，只产建议不落参数
     */
    public function paramBackcalc(): JsonResponse
    {
        return $this->ok($this->backCalcService->report());
    }

    /**
     * 待排任务池
     */
    public function taskPool(Request $request): JsonResponse
    {
        return $this->ok($this->ganttDataService->taskPool($request->only(['priority', 'product_id', 'keyword', 'limit'])));
    }

    /**
     * 甘特时间线数据
     */
    public function timeline(Request $request): JsonResponse
    {
        $start = $request->input('start') ? Carbon::parse($request->input('start'))->startOfDay() : now()->startOfDay();
        // 日期型 end 表示“包含这一天”，不能停在当天 00:00，否则当天 08:00
        // 开始的排程会被时间线查询排除。
        $end = $request->input('end')
            ? Carbon::parse($request->input('end'))->endOfDay()
            : now()->startOfDay()->addDays(7)->endOfDay();

        if ($end->lte($start)) {
            return $this->fail('时间窗不合法（end 必须晚于 start）', 422, 422);
        }

        // 时间窗上限保护（最多 31 天）
        if ($start->diffInDays($end) > 31) {
            return $this->fail('时间窗最大 31 天', 422, 422);
        }

        return $this->ok($this->ganttDataService->timeline($start, $end));
    }

    /**
     * 插单影响预览（受影响订单延期清单）——GET 参数与 POST /insert 一致
     */
    public function ordersAffected(Request $request): JsonResponse
    {
        $request->validate([
            'task_id' => ['required', 'integer'],
            'machine_id' => ['required', 'integer'],
            'plan_start' => ['required', 'date'],
            'strategy' => ['nullable', 'in:wait,interrupt'],
            'mold_id' => ['nullable', 'integer'],
        ]);

        try {
            $preview = $this->insertService->preview(
                (int) $request->input('task_id'),
                (int) $request->input('machine_id'),
                (string) $request->input('plan_start'),
                (string) $request->input('strategy', 'wait'),
                $request->input('mold_id') !== null ? (int) $request->input('mold_id') : null,
                $request->boolean('force_interrupt'),
            );
        } catch (ScheduleException $e) {
            return response()->json(['code' => $e->getCode(), 'message' => $e->getMessage(), 'data' => ['errors' => $e->errors()]], $e->getCode() >= 100 && $e->getCode() < 600 ? $e->getCode() : 409);
        }

        return $this->ok([
            'task' => $preview['task'],
            'strategy' => $preview['strategy'],
            'interrupted' => $preview['interrupted'],
            'affected_orders' => $preview['affected_orders'],
            'changed_tasks' => $preview['changed_tasks'],
            'insert_chain' => $preview['insert_chain'],
        ]);
    }

    /**
     * 任务排程明细（任务详情 + 工序排程，甘特/明细双视图）
     */
    public function detail(Request $request): JsonResponse
    {
        $request->validate(['task_id' => ['required', 'integer']]);

        $detail = $this->ganttDataService->detail((int) $request->input('task_id'));
        if ($detail === null) {
            return $this->fail('任务不存在', 404, 404);
        }

        return $this->ok($detail);
    }

    /**
     * 已排产任务明细列表（默认全部，支持按任务号筛选）
     */
    public function details(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'order_no' => ['nullable', 'string', 'max:100'],
            'product' => ['nullable', 'string', 'max:100'],
            'machine' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return $this->ok($this->ganttDataService->details($request->only(['date', 'keyword', 'order_no', 'product', 'machine', 'limit'])));
    }
}
