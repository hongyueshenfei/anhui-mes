<?php

namespace App\Modules\Scheduling\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ScheduleSuggestion;
use App\Modules\Scheduling\Exceptions\ScheduleException;
use App\Modules\Scheduling\Services\SuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 排程建议控制器（APS一期：建议层）
 *
 * GET  /api/schedule/suggestions            建议清单（默认 pending）
 * POST /api/schedule/suggestions/{id}/accept   采纳（plan 对齐 forecast + 版本快照）
 * POST /api/schedule/suggestions/{id}/dismiss  驳回（留痕）
 */
class ScheduleSuggestionController extends Controller
{
    public function __construct(
        private readonly SuggestionService $suggestionService,
    ) {
    }

    /**
     * 建议清单（status 过滤，默认 pending）
     */
    public function index(Request $request): JsonResponse
    {
        $status = (string) $request->input('status', ScheduleSuggestion::STATUS_PENDING);

        $query = ScheduleSuggestion::with('task:id,task_no,delivery_date')->orderByDesc('id');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return $this->ok($query->limit(100)->get());
    }

    /**
     * 采纳建议：受影响任务未完工工序 plan 对齐 forecast（版本快照+留痕）
     */
    public function accept(Request $request, int $id): JsonResponse
    {
        $suggestion = ScheduleSuggestion::findOrFail($id);

        try {
            $result = $this->suggestionService->accept($suggestion, (int) $request->user()?->id);
        } catch (ScheduleException $e) {
            return $this->suggestionError($e);
        }

        return $this->ok($result, '顺延建议已采纳（计划对齐预计，版本快照已写入，可继续手动调整）');
    }

    /**
     * 驳回建议（留痕，不改排程）
     */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        $suggestion = ScheduleSuggestion::findOrFail($id);

        try {
            $this->suggestionService->dismiss($suggestion, (int) $request->user()?->id);
        } catch (ScheduleException $e) {
            return $this->suggestionError($e);
        }

        return $this->ok(['id' => $suggestion->id], '顺延建议已驳回');
    }

    /**
     * 业务异常 → 统一响应
     */
    private function suggestionError(ScheduleException $e): JsonResponse
    {
        $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409;

        return response()->json([
            'code' => $status,
            'message' => $e->getMessage(),
            'data' => ['errors' => $e->errors()],
        ], $status);
    }
}
