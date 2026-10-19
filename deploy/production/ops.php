<?php

// 仅供容器 CLI 运维；不在 public 目录，不创建带默认密码的账号。
use App\Models\OpType;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('production')) {
    fwrite(STDERR, "此入口仅用于生产部署。\n"); exit(1);
}

try {
    switch ($argv[1] ?? '') {
        case 'bootstrap':
            // 只在初次安装建立权限；升级不覆盖运维人员调整过的权限。
            if (! Role::where('name', 'admin')->where('guard_name', 'web')->exists()) {
                if (User::count() === 0) {
                    // 新库的迁移可能已创建 statistician 角色；以管理员是否存在判断初始化状态。
                    $app->make(Database\Seeders\RoleSeeder::class)->run();
                } else {
                    // 已有用户时只补管理员角色，避免重置现场自定义角色权限。
                    foreach (Database\Seeders\RoleSeeder::PERMISSIONS as $permission) {
                        Permission::findOrCreate($permission, 'web');
                    }
                    $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
                    $role->syncPermissions(Database\Seeders\RoleSeeder::PERMISSIONS);
                }
            }
            if (OpType::count() === 0) {
                $app->make(Database\Seeders\OpTypeSeeder::class)->run();
            }
            $app->make(Database\Seeders\ShiftConfigSeeder::class)->run();
            echo "基础权限、工序字典和缺失的班制配置已就绪；未导入演示业务数据。\n";
            break;
        case 'admin':
            $input = json_decode(stream_get_contents(STDIN, 8192), true, flags: JSON_THROW_ON_ERROR);
            $employee = $input['employee_no'] ?? '';
            $password = $input['password'] ?? '';
            if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $employee) || strlen($password) < 12) {
                throw new DomainException('管理员工号或密码格式不合要求');
            }
            if (User::withTrashed()->where('employee_no', $employee)->exists()) {
                throw new DomainException('该工号已存在，拒绝覆盖账号或密码');
            }
            DB::transaction(function () use ($employee, $password) {
                $user = User::create([
                    'employee_no' => $employee, 'name' => '系统管理员',
                    'password_hash' => Hash::make($password),
                    'job_type' => User::JOB_ADMIN, 'status' => 1,
                ]);
                $user->assignRole('admin');
            });
            echo "管理员已创建：{$employee}（密码未输出）。\n";
            break;
        case 'health':
            DB::select('SELECT 1');
            Redis::connection()->ping();
            Redis::connection('cache')->ping();
            $ai = Http::timeout(10)->withHeaders(['X-AI-Token' => config('ai.service_token')])
                ->get(rtrim(config('ai.service_url'), '/').'/health');
            if (! $ai->successful() || $ai->json('solver') !== 'ortools') {
                throw new RuntimeException('AI 未通过健康检查');
            }
            echo "MySQL / Redis 默认库与缓存库 / 内部 AI 鉴权与 OR-Tools：通过。\n";
            break;
        default:
            throw new RuntimeException('未知运维动作');
    }
} catch (Throwable $error) {
    // 数据库异常可能包含 SQL 或连接信息，不把异常原文输出到操作终端。
    fwrite(STDERR, $error instanceof DomainException ? $error->getMessage()."\n" : "运维动作失败：".get_class($error)."。请在受控环境查看服务日志。\n");
    exit(1);
}
