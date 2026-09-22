<?php

namespace App\Modules\System\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 操作审计查询控制器（一期验收 A11：BOM 发布、模具修改等写入可查）
 */
class AuditController extends Controller
{
    /**
     * 审计日志列表（分页 + 过滤）
     */
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()
            ->with('user:id,employee_no,name')
            ->when($request->input('module'), fn ($q, $module) => $q->where('module', $module))
            ->when($request->input('action'), fn ($q, $action) => $q->where('action', $action))
            ->when($request->input('target_type'), fn ($q, $type) => $q->where('target_type', $type))
            ->when($request->input('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($request->input('date_from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($request->input('date_to'), fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 20), 100);

        return $this->ok($query->paginate($perPage));
    }
}