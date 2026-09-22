<?php

namespace App\Modules\System\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\System\Requests\UserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

/**
 * 用户管理控制器（CRUD + 角色分配 + 密码重置 + 微信/企微绑定字段）
 */
class UserController extends Controller
{
    /**
     * 用户列表（分页，支持 keyword 搜索与 workshop_id 过滤）
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->with(['workshop:id,name', 'roles:id,name'])
            ->when($request->input('keyword'), function ($q, $keyword) {
                $q->where(function ($w) use ($keyword) {
                    $w->where('employee_no', 'like', "%{$keyword}%")
                        ->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%");
                });
            })
            ->when($request->input('workshop_id'), fn ($q, $workshopId) => $q->where('workshop_id', $workshopId))
            ->when($request->input('status') !== null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id');

        $perPage = min((int) $request->input('per_page', 15), 100);

        return $this->ok($query->paginate($perPage), 'ok');
    }

    /**
     * 用户下拉数据源（在职用户 status=1，不分页，支持 workshop_id 过滤）
     *
     * GET /api/system/users/options?workshop_id=
     * 返回 [{id, name, employee_no, workshop_id, workshop_name}]，供工序负责人等选择
     */
    public function options(Request $request): JsonResponse
    {
        $users = User::query()
            ->where('status', 1)
            ->when($request->input('workshop_id'), fn ($q, $workshopId) => $q->where('workshop_id', $workshopId))
            ->orderBy('id')
            ->get(['id', 'name', 'employee_no', 'workshop_id'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'employee_no' => $u->employee_no,
                'workshop_id' => $u->workshop_id,
                'workshop_name' => $u->workshop?->name,
            ])
            ->all();

        return $this->ok($users);
    }

    /**
     * 新建用户
     */
    public function store(UserRequest $request): JsonResponse
    {
        $user = User::create([
            'employee_no' => $request->input('employee_no'),
            'name' => $request->input('name'),
            'phone' => $request->input('phone', null),
            'password_hash' => $request->filled('password') ? Hash::make($request->input('password')) : null,
            'wecom_userid' => $request->input('wecom_userid', null),
            'wx_openid' => $request->input('wx_openid', null),
            'workshop_id' => $request->input('workshop_id', null),
            'job_type' => $request->input('job_type', User::JOB_WORKER),
            'status' => (int) $request->input('status', 1),
        ]);

        if ($request->filled('roles')) {
            $user->syncRoles($request->input('roles'));
        }

        return $this->ok($this->formatUser($user), '用户创建成功', 201);
    }

    /**
     * 更新用户（基本信息与绑定字段）
     */
    public function update(UserRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $data = [
            'name' => $request->input('name'),
            'phone' => $request->input('phone', $user->phone),
            'wecom_userid' => $request->has('wecom_userid') ? $request->input('wecom_userid') : $user->wecom_userid,
            'wx_openid' => $request->has('wx_openid') ? $request->input('wx_openid') : $user->wx_openid,
            'workshop_id' => $request->has('workshop_id') ? $request->input('workshop_id') : $user->workshop_id,
            'job_type' => $request->input('job_type', $user->job_type),
            'status' => $request->has('status') ? (int) $request->input('status') : $user->status,
        ];

        // 密码单独处理（Update 不强制带密码）
        if ($request->filled('password')) {
            $data['password_hash'] = Hash::make($request->input('password'));
        }

        $user->update($data);

        if ($request->filled('roles')) {
            $user->syncRoles($request->input('roles'));
        }

        return $this->ok($this->formatUser($user->fresh(['workshop:id,name', 'roles:id,name'])), '用户更新成功');
    }

    /**
     * 删除用户（软删除；系统管理员/班组长受保护，返回 409）
     */
    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->employee_no === 'admin') {
            return response()->json([
                'code' => 409,
                'message' => '系统内置管理员不可删除',
                'data' => null,
            ], 409);
        }

        // 引用保护：担任车间班组长（job_type=leader）的用户需先调整岗位，避免车间无人负责
        if ($user->job_type === User::JOB_LEADER) {
            return response()->json([
                'code' => 409,
                'message' => '该用户担任车间班组长，请先调整岗位后再删除',
                'data' => null,
            ], 409);
        }

        $user->delete();

        return $this->ok(null, '用户已删除');
    }

    /**
     * 分配角色（可多角色）
     */
    public function assignRoles(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $user = User::findOrFail($id);
        $user->syncRoles($request->input('roles'));

        return $this->ok(['roles' => $user->getRoleNames()], '角色分配成功');
    }

    /**
     * 重置密码（仅限有 password_hash 的账号；无则开通账号密码）
     */
    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'min:6', 'max:64']]);

        $user = User::findOrFail($id);
        $user->update(['password_hash' => Hash::make($request->input('password'))]);

        Log::info('密码已重置', ['employee_no' => $user->employee_no, 'operator' => $request->user()?->employee_no]);

        return $this->ok(null, '密码重置成功');
    }

    /**
     * 组装用户响应（含角色/车间/绑定状态）
     */
    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'employee_no' => $user->employee_no,
            'name' => $user->name,
            'phone' => $user->phone,
            'job_type' => $user->job_type,
            'status' => $user->status,
            'workshop_id' => $user->workshop_id,
            'workshop_name' => $user->workshop?->name,
            'wecom_userid' => $user->wecom_userid,
            'wx_openid' => $user->wx_openid,
            'wecom_bound' => $user->wecom_userid !== null,
            'wx_bound' => $user->wx_openid !== null,
            'roles' => $user->roles->pluck('name'),
            'created_at' => $user->created_at?->toDateTimeString(),
        ];
    }
}