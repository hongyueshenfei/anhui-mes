<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 工单操作日志（编辑/开始/完结/撤销 统一留痕）
 *
 * - 仅 INSERT，配合审计；from_status/to_status 记录状态流转
 * - meta 存动作附加信息（如报工数量、冲减原因键等）
 */
class WorkOrderLog extends Model
{
    /** 动作 */
    public const ACTION_EDIT = 'edit';
    public const ACTION_START = 'start';
    public const ACTION_COMPLETE = 'complete';
    public const ACTION_REVOKE = 'revoke';
    public const ACTION_ROUTE_CONFIRM = 'route_confirm';
    public const ACTION_PAUSE = 'pause';
    public const ACTION_ABORT = 'abort';
    public const ACTION_CLOSE = 'close';
    public const ACTION_SPLIT = 'split';

    public const ACTION_LABELS = [
        self::ACTION_EDIT => '编辑',
        self::ACTION_START => '开始',
        self::ACTION_COMPLETE => '完结',
        self::ACTION_REVOKE => '撤销',
        self::ACTION_ROUTE_CONFIRM => '工艺确认',
        self::ACTION_PAUSE => '停工',
        self::ACTION_ABORT => '中止',
        self::ACTION_CLOSE => '关闭',
        self::ACTION_SPLIT => '生产拆单',
    ];

    protected $fillable = [
        'order_id',
        'action',
        'from_status',
        'to_status',
        'operator_id',
        'operator_name',
        'remark',
        'meta',
    ];

    protected $casts = [
        'operator_id' => 'integer',
        'meta' => 'array',
    ];

    /**
     * 所属工单
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * 操作人
     */
    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * 动作中文名
     */
    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }
}
