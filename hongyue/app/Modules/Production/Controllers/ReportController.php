<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ProdTask;
use App\Modules\Production\Exceptions\ReportException;
use App\Modules\Production\Requests\ReportRequest;
use App\Modules\Production\Services\ReportService;
use App\Modules\System\Services\ConfigService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 报工控制器（C2~C7：扫码/点选/代报/冲减/离线补传/台账）
 *
 * GET  /api/reports                    报工台账（PC 明细页）
 * POST /api/reports                    报工/冲减
 * POST /api/reports/verify             防重校验
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService,
    ) {
    }

    /**
     * 报工台账（时间/操作人/任务/机台/数量/来源/冲减入口）
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        $reports = $this->reportService->index($request->only([
            'task_id', 'worker_id', 'machine_id', 'source', 'sign', 'date_from', 'date_to',
        ]), $perPage);

        return $this->ok($reports);
    }

    /**
     * 报工统计汇总（按实际作业日期、任务、工序、机台/产线聚合）
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'machine_id' => ['nullable', 'integer', 'min:1'],
            'machine_keyword' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->ok($this->reportService->summary($filters));
    }

    /**
     * 提交报工（正常/冲减/代报/离线补传）
     *
     * body: {task_id, qty(负=冲减), defect_qty?, schedule_id?, machine_id?, source?, offline_flag?, remark?, report_time?, start_time?, end_time?}
     */
    public function store(ReportRequest $request): JsonResponse
    {
        try {
            $result = $this->reportService->report($request->validated(), $request->user());
        } catch (ReportException $e) {
            return response()->json([
                'code' => $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422,
                'message' => $e->getMessage(),
                'data' => ['error_code' => $e->errorCode()] + $e->payload(),
            ], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }

        return $this->ok($result, $result['is_correction'] ? '冲减已记录（更正留痕）' : '报工成功', 201);
    }

    /**
     * 防重校验（弱网补传/重复点选提示）
     *
     * body: {task_id, qty, worker_id?}
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'task_id' => ['required', 'integer'],
            'qty' => ['required', 'integer', 'not_in:0'],
            'worker_id' => ['nullable', 'integer'],
        ]);

        return $this->ok($this->reportService->verifyDuplication(
            (int) $request->input('task_id'),
            (int) $request->input('qty'),
            $request->input('worker_id') !== null ? (int) $request->input('worker_id') : null,
            (int) $request->user()?->id,
        ));
    }
}
