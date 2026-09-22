<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 初始管理员（部署脚本/本地开发创建）
 *
 * 账号：admin / 123456（首次登录后请立即修改密码）
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['employee_no' => 'admin'],
            [
                'name' => '系统管理员',
                'phone' => '13800000000',
                'password_hash' => Hash::make('123456'),
                'job_type' => User::JOB_ADMIN,
                'status' => 1,
            ]
        );

        $admin->assignRole('admin');
    }
}