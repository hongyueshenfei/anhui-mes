<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 集成日志（所有外部调用留痕）—— 对应架构 §3.7 integration_logs 表
 *
 * 幂等键：api_type + biz_type + biz_id + status=success 唯一（重推前查重）
 */
class IntegrationLog extends Model
{
    public const UPDATED_AT = null;

    /** 方向 */
    public const DIR_IN = 'in';
    public const DIR_OUT = 'out';

    /** 状态 */
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRY = 'retry';

    /** 目标 */
    public const TARGET_K3 = 'k3';
    public const TARGET_MINI_PROGRAM = 'mini_program';
    public const TARGET_AI = 'ai';

    protected $fillable = [
        'direction',
        'target',
        'api_type',
        'biz_type',
        'biz_id',
        'request_body',
        'response_body',
        'status',
        'retry_count',
        'error_msg',
        'created_at',
        'finished_at',
    ];

    protected $casts = [
        'biz_id' => 'integer',
        'request_body' => 'array',
        'response_body' => 'array',
        'retry_count' => 'integer',
        'created_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * 幂等查询：同业务键是否已有成功记录
     */
    public static function hasSuccess(string $apiType, string $bizType, int $bizId): bool
    {
        return self::where('api_type', $apiType)
            ->where('biz_type', $bizType)
            ->where('biz_id', $bizId)
            ->where('status', self::STATUS_SUCCESS)
            ->exists();
    }

    /**
     * 写一条日志（留痕，不抛异常）
     */
    public static function capture(array $data): void
    {
        try {
            self::create($data + ['created_at' => now()]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('集成日志写入失败', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 写一条日志并返回 id（留痕，不抛异常）
     */
    public static function captureAndGetId(array $data): ?int
    {
        try {
            return (int) self::create($data + ['created_at' => now()])->id;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('集成日志写入失败', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function getStatusLabelAttribute(): string
    {
        return ['success' => '成功', 'failed' => '失败', 'retry' => '重试中'][$this->status] ?? $this->status;
    }
}