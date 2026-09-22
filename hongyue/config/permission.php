<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 权限模型
    |--------------------------------------------------------------------------
    */
    'models' => [
        'permission' => Spatie\Permission\Models\Permission::class,
        'role' => Spatie\Permission\Models\Role::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | 表名
    |--------------------------------------------------------------------------
    */
    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    /*
    |--------------------------------------------------------------------------
    | 列名
    |--------------------------------------------------------------------------
    */
    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | 注册权限检查方法（Gate::before）
    |--------------------------------------------------------------------------
    */
    'register_permission_check_method' => true,

    'register_octane_reset_listener' => false,

    /*
    |--------------------------------------------------------------------------
    | 团队功能（不使用）
    |--------------------------------------------------------------------------
    */
    'teams' => false,

    'team_column_name' => 'team_id',

    /*
    |--------------------------------------------------------------------------
    | 默认权限守卫名（与本项目 auth.defaults.guard=web 对齐）
    |--------------------------------------------------------------------------
    */
    'guard_name' => 'web',

    /*
    |--------------------------------------------------------------------------
    | 异常
    |--------------------------------------------------------------------------
    */
    'exceptions' => [
        'role_already_exists' => Spatie\Permission\Exceptions\RoleAlreadyExists::class,
        'permission_already_exists' => Spatie\Permission\Exceptions\PermissionAlreadyExists::class,
        'role_does_not_exist' => Spatie\Permission\Exceptions\RoleDoesNotExist::class,
        'permission_does_not_exist' => Spatie\Permission\Exceptions\PermissionDoesNotExist::class,
        'permission_is_guarded' => Spatie\Permission\Exceptions\PermissionAlreadyExists::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | 缓存
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'expiration_time' => \DateInterval::createFromDateString('24 hours'),
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],

];