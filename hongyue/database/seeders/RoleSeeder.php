<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 角色与权限矩阵（一期 RBAC）
 *
 * 七角色：管理员 admin / 工程师 engineer / 计划员 planner / 班组长 leader / 统计员 statistician / 工人 worker / 业务员 sales
 * 权限命名规则：module.resource.action
 */
class RoleSeeder extends Seeder
{
    /** 权限清单 */
    public const PERMISSIONS = [
        // 系统管理
        'system.user.manage',
        'system.role.manage',
        'system.config.manage',
        'audit.view',
        // 机台
        'plm.machine.manage',
        'plm.machine.view',
        // 班组
        'plm.team.manage',
        'plm.team.view',
        // 产品
        'plm.product.manage',
        'plm.product.view',
        // BOM
        'plm.bom.manage',
        'plm.bom.view',
        'plm.bom.publish',
        // 工艺路线
        'plm.route.manage',
        'plm.route.view',
        // 模具
        'plm.mold.manage',
        'plm.mold.view',
        // 订单中心（二期）
        'order.manage',
        'order.view',
        // 排产（二期）
        'schedule.manage',
        'schedule.view',
        // 报工（三期）
        'report.manage',
        'report.view',
        // 入库（三期）
        'warehouse.manage',
        'warehouse.view',
        // 集成（三期）
        'integration.manage',
        'integration.view',
        // 看板（四期）
        'dashboard.view',
        'dashboard.manage',
        // 报价（五期）
        'quote.view',
        'quote.manage',
    ];

    /** 角色 → 权限映射 */
    public const ROLE_PERMISSIONS = [
        'admin' => self::PERMISSIONS, // 管理员：全部
        'engineer' => [
            'plm.machine.manage', 'plm.machine.view',
            'plm.team.manage', 'plm.team.view',
            'plm.product.manage', 'plm.product.view',
            'plm.bom.manage', 'plm.bom.view', 'plm.bom.publish',
            'plm.route.manage', 'plm.route.view',
            'plm.mold.manage', 'plm.mold.view',
        ],
        'planner' => [
            'plm.machine.view', 'plm.team.view',
            'plm.product.view', 'plm.bom.view', 'plm.route.view', 'plm.mold.view',
            // 二期：计划员负责订单录入与排产
            'order.manage', 'order.view',
            'schedule.manage', 'schedule.view',
            // 三期：计划员看报工、管入库、查集成
            'report.view',
            'warehouse.manage', 'warehouse.view',
            'integration.view', 'integration.manage',
            // 四期：看板
            'dashboard.view', 'dashboard.manage',
        ],
        'leader' => [
            'plm.machine.view', 'plm.team.view',
            'plm.product.view', 'plm.bom.view', 'plm.route.view', 'plm.mold.view',
            'order.view', 'schedule.view',
            // 三期：班组长报工/代报 + 入库
            'report.manage', 'report.view',
            'warehouse.manage', 'warehouse.view',
            // 四期：看板红灯处理
            'dashboard.view', 'dashboard.manage',
        ],
        'statistician' => [
            'plm.machine.view', 'plm.product.view',
            'order.view', 'schedule.view',
            'report.manage', 'report.view',
            'warehouse.view',
            'dashboard.view',
        ],
        'worker' => [
            'plm.machine.view', 'plm.product.view',
            // 暂停工人自报，保留历史台账查看
            'report.view',
            'warehouse.view',
            'dashboard.view',
        ],
        'sales' => [
            'plm.product.view', 'plm.mold.view',
            'order.view',
            'report.view',
            'dashboard.view',
            // 五期：业务员负责报价
            'quote.view', 'quote.manage',
        ],
    ];

    public function run(): void
    {
        // 1. 创建权限
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // 2. 创建角色并授予权限
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
        }
    }
}
