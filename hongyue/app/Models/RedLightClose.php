<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 红灯关闭记录（四期红灯看板持久化）—— 对应 red_light_closes 表
 *
 * 唯一键 (source, task_id)：重算红灯时跳过已关闭项，避免重复暴露。
 */
class RedLightClose extends Model
{
    public const UPDATED_AT = null;

    /** 红灯来源 */
    public const SOURCE_UNSCHEDULED = 'unscheduled';
    public const SOURCE_NO_REPORT = 'no_report';
    public const SOURCE_OVERDUE = 'overdue';
    public const SOURCE_BEHIND = 'behind';

    protected $fillable = [
        'source',
        'task_id',
        'closed_by',
        'user_id',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'task_id' => 'integer',
        'user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * 该 (source, task_id) 是否已关闭
     */
    public static function isClosed(string $source, int $taskId): bool
    {
        return self::where('source', $source)->where('task_id', $taskId)->exists();
    }

    /**
     * 批量已关闭键（供红灯引擎过滤）
     *
     * @return array<string, true> 键为 "source:task_id"
     */
    public static function closedKeys(): array
    {
        return self::query()
            ->get(['source', 'task_id'])
            ->mapWithKeys(fn ($row) => ["{$row->source}:{$row->task_id}" => true])
            ->all();
    }
}
