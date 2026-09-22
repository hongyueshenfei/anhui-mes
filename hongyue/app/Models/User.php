<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * 用户/员工
 *
 * 对应架构 §3.1 users 表：
 * - employee_no 工号（微信绑定时必填），登录账号即工号
 * - password_hash 账号密码回退登录（可空=仅企微/微信免密）
 * - wecom_userid / wx_openid 免密通道
 * - workshop_id 所属车间（可空=管理岗）；job_type 岗位类型
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** 岗位类型常量 */
    public const JOB_ADMIN = 'admin';
    public const JOB_ENGINEER = 'engineer';
    public const JOB_PLANNER = 'planner';
    public const JOB_LEADER = 'leader';
    public const JOB_STATISTICIAN = 'statistician';
    public const JOB_WORKER = 'worker';
    public const JOB_SALES = 'sales';
    public const JOB_BOSS = 'boss';

    protected $fillable = [
        'employee_no',
        'name',
        'phone',
        'password_hash',
        'wecom_userid',
        'wx_openid',
        'workshop_id',
        'job_type',
        'status',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'workshop_id' => 'integer',
        'status' => 'integer',
    ];

    protected $appends = ['display_name'];

    /**
     * 展示名：姓名（工号）
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name.'（'.$this->employee_no.'）';
    }

    /**
     * 所属车间（可空=管理岗）
     */
    public function workshop()
    {
        return $this->belongsTo(Workshop::class, 'workshop_id');
    }

    /**
     * 校验账号密码（工号 + 密码）
     */
    public static function verifyPassword(string $employeeNo, string $password): ?self
    {
        $user = self::where('employee_no', $employeeNo)->first();

        if (! $user || ! $user->password_hash || ! \Illuminate\Support\Facades\Hash::check($password, $user->password_hash)) {
            return null;
        }

        return $user;
    }

    /**
     * 是否启用
     */
    public function isActive(): bool
    {
        return (int) $this->status === 1;
    }
}
