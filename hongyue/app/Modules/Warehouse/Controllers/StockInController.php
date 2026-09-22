<?php

namespace App\Modules\Warehouse\Controllers;

use App\Http\Controllers\Controller;
use App\Models\StockIn;
use App\Modules\Warehouse\Exceptions\StockInException;
use App\Modules\Warehouse\Services\K3StockInPushService;
use App\Modules\Warehouse\Services\StockInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 入库控制器（C8：分批入库 / 可入库量 / 推送状态 / 重试）
 */
class StockInController extends Controller
{
    public function __construct(
        private readonly StockInService $stockInService,
        private readonly K3StockInPushService $pushService,
    ) {
    }

    /**
     * 可入库量（C8）：报工完成量 − 已入库量
     *
     * GET /api/warehouse/available-qty?task_id=
     */
    public function availableQty(Request $request): JsonResponse
    {
        $request->validate(['task_id' => ['required', 'integer']]);

        return $this->ok($this->stockInService->availableQty((int) $request->input('task_id')));
    }

    /**
     * 分批入库（超量强校验 422）
     *
     * POST /api/warehouse/ins  body: {task_id, qty, remark?}
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'task_id' => ['required', 'integer', 'exists:prod_tasks,id'],
            'qty' => ['required', 'integer', 'min:1'],
            'remark' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->stockInService->create(
                (int) $request->input('task_id'),
                (int) $request->input('qty'),
                (int) $request->user()?->id,
                $request->input('remark'),
            );
        } catch (StockInException $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422;

            return response()->json([
                'code' => $status,
                'message' => $e->getMessage(),
                'data' => ['error_code' => $e->errorCode()] + $e->payload(),
            ], $status);
        }

        return $this->ok($result, "入库单 {$result['stock_in']->ins_no} 已确认（待推送金蝶）", 201);
    }

    /**
     * 待入库任务列表（可入库量 > 0）
     *
     * GET /api/warehouse/pending-tasks?page=&per_page=
     */
    public function pendingTasks(Request $request): JsonResponse
    {
        return $this->ok($this->stockInService->pendingTasks(
            (int) $request->input('page', 1),
            (int) $request->input('per_page', 15),
        ));
    }

    /**
     * 入库单列表（推送状态过滤）
     *
     * GET /api/warehouse/ins?k3_push_status=&task_id=
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        return $this->ok($this->stockInService->index($request->only(['task_id', 'k3_push_status', 'ins_status']), $perPage));
    }

    /**
     * 手动重推金蝶（幂等：成功记录存在则跳过）
     *
     * POST /api/warehouse/ins/{id}/push
     */
    public function push(int $id): JsonResponse
    {
        $stockIn = StockIn::findOrFail($id);

        try {
            $result = $this->pushService->push($stockIn);
        } catch (\Throwable $e) {
            return response()->json([
                'code' => 502,
                'message' => '推送失败（已留痕，可稍后重试）：'.$e->getMessage(),
                'data' => ['stock_in_id' => $stockIn->id, 'k3_push_status' => $stockIn->fresh()->k3_push_status],
            ], 502);
        }

        return $this->ok($result, $result['message']);
    }
}