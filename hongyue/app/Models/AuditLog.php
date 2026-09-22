<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 操作审计
 *
 * 对应架构 §3.7 audit_logs 表：
 * module+action+target，detail 存放请求参数与结果状态，ip 记录来源。
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'module',
        'action',
        'target_type',
        'target_id',
        'detail',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'target_id' => 'integer',
        'detail' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * 记录一条审计日志
     */
    public static function record(
        ?\Illuminate\Contracts\Auth\Authenticatable $user,
        string $module,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $detail = [],
        ?string $ip = null,
    ): self {
        return self::create([
            'user_id' => $user?->getAuthIdentifier(),
            'module' => $module,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'detail' => $detail,
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}