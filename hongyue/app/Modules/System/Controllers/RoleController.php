<?php

namespace App\Modules\System\Controllers;

use App\Http\Controllers\Controller;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * 角色管理控制器
 */
class RoleController extends Controller
{
    /**
     * 角色列表（含权限）
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:id,name')->orderBy('id')->get();

        return $this->ok($roles->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'guard_name' => $role->guard_name,
            'permissions' => $role->permissions->pluck('name'),
            'created_at' => $role->created_at?->toDateTimeString(),
        ]));
    }

    /**
     * 新建角色
     * 请求体：{name, permissions: [权限名]}
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:64', 'unique:roles,name'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role = Role::create(['name' => $request->input('name'), 'guard_name' => 'web']);
        $role->syncPermissions($request->input('permissions', []));

        return $this->ok(['id' => $role->id, 'name' => $role->name], '角色创建成功', 201);
    }

    /**
     * 更新角色权限
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:64', 'unique:roles,name,'.$id],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role = Role::findOrFail($id);

        if ($request->filled('name')) {
            $role->name = $request->input('name');
            $role->save();
        }

        if ($request->has('permissions')) {
            $role->syncPermissions($request->input('permissions'));
        }

        return $this->ok(null, '角色更新成功');
    }

    /**
     * 删除角色（内置角色保护）
     */
    public function destroy(int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if (array_key_exists($role->name, RoleSeeder::ROLE_PERMISSIONS)) {
            return $this->fail('内置角色不可删除', 422, 422);
        }

        $role->delete();

        return $this->ok(null, '角色已删除');
    }
}