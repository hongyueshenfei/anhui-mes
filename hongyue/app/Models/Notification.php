<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 消息通知（架构 §3.7 notifications 表）
 *
 * 企业微信应用消息：红灯/临期推送责任人；mock_mode 沙箱模拟发送落库，
 * 真实推送失败仅留 failed 记录（不阻断业务）。
 */
class Notification extends Model
{
    /** 渠道 */
    public const CHANNEL_WECOM = 'wecom';
    public const CHANNEL_MINI_PROGRAM = 'mini_program';

    /** 状态 */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** 模板 */
    public const TEMPLATE_RED_LIGHT = 'red_light';
    public const TEMPLATE_DUE_SOON = 'due_soon';

    protected $fillable = [
        'user_id',
        'channel',
        'template',
        'title',
        'content',
        'status',
        'target_type',
        'target_id',
        'error_msg',
        'sent_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'target_id' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
