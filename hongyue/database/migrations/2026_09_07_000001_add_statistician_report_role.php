<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $reportManage = Permission::findOrCreate('report.manage', 'web');
        $reportView = Permission::findOrCreate('report.view', 'web');

        $statistician = Role::findOrCreate('statistician', 'web');
        $statistician->syncPermissions([$reportManage, $reportView]);

        // 暂停工人自报：历史报工不变，仅撤销后续提交权限。
        $worker = Role::query()
            ->where('name', 'worker')
            ->where('guard_name', 'web')
            ->first();
        $worker?->revokePermissionTo($reportManage);
    }

    public function down(): void
    {
        $worker = Role::query()
            ->where('name', 'worker')
            ->where('guard_name', 'web')
            ->first();
        $worker?->givePermissionTo(Permission::findByName('report.manage', 'web'));

        Role::query()
            ->where('name', 'statistician')
            ->where('guard_name', 'web')
            ->first()?->delete();
    }
};
