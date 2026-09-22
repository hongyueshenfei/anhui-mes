<?php

namespace App\Modules\System\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 消息通知服务（架构 §3.7 / 验收 D8 企微消息）
 *
 * - 企业微信应用消息：corp_id/agent_id/secret 已配置 + mock_mode=false → 真实推送；
 * - mock_mode=true（沙箱/演示）→ 模拟发送，落库 status=sent，不发起真实 HTTP；
 * - 真实推送失败 → 落库 status=failed 仅留痕，不抛异常阻断业务。
 */
class NotificationService
{
    /**
     * 红灯通知（模板 red_light）
     */
    public function notifyRedLight(array $item): ?Notification
    {
        $ownerId = $item['owner_id'] ?? null;
        if (! $ownerId) {
            return null;
        }

        $title = sprintf('红灯提醒：%s · %s', $item['source_label'] ?? '', $item['task_no'] ?? '');
        $content = sprintf(
            '【%s】%s：%s 当前进度 %s%%，原因：%s',
            $item['source_label'] ?? '红灯',
            $item['product_name'] ?? $item['task_no'] ?? '',
            $item['task_no'] ?? '',
            $item['progress_pct'] ?? 0,
            $item['reason'] ?? ''
        );

        return $this->sendWecom(
            (int) $ownerId,
            Notification::TEMPLATE_RED_LIGHT,
            $title,
            $content,
            'task',
            $item['task_id'] ?? null
        );
    }

    /**
     * 临期订单通知（模板 due_soon）
     */
    public function notifyDueSoon(array $order): ?Notification
    {
        $planner = User::where('job_type', User::JOB_PLANNER)->orderBy('id')->first();
        if (! $planner) {
            return null;
        }

        $title = '临期提醒：订单 '.$order['order_no'];
        $content = sprintf(
            '订单 %s（%s）交期 %s，剩余 %d 天，当前进度 %s%%，请关注排产。',
            $order['order_no'],
            $order['customer_name'] ?? '',
            $order['delivery_date'] ?? '',
            $order['days_left'] ?? 0,
            $order['progress_pct'] ?? 0
        );

        return $this->sendWecom(
            (int) $planner->id,
            Notification::TEMPLATE_DUE_SOON,
            $title,
            $content,
            'order',
            $order['order_id'] ?? null
        );
    }

    /**
     * 发送企业微信应用消息（mock_mode 沙箱模拟；失败仅留痕）
     */
    public function sendWecom(int $userId, string $template, string $title, string $content, ?string $targetType = null, ?int $targetId = null): Notification
    {
        if ((bool) config('wecom.mock_mode', false)) {
            // 沙箱/演示：模拟发送成功，落库留痕
            return Notification::create([
                'user_id' => $userId,
                'channel' => Notification::CHANNEL_WECOM,
                'template' => $template,
                'title' => $title,
                'content' => $content,
                'status' => Notification::STATUS_SENT,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'sent_at' => now(),
            ]);
        }

        try {
            $this->pushWecomMessage($userId, $content, $title);

            return Notification::create([
                'user_id' => $userId,
                'channel' => Notification::CHANNEL_WECOM,
                'template' => $template,
                'title' => $title,
                'content' => $content,
                'status' => Notification::STATUS_SENT,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('企微消息推送失败（仅留痕）', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return Notification::create([
                'user_id' => $userId,
                'channel' => Notification::CHANNEL_WECOM,
                'template' => $template,
                'title' => $title,
                'content' => $content,
                'status' => Notification::STATUS_FAILED,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'error_msg' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }

    /**
     * 真实企微推送（企业微信应用消息 textcard 简化）—— 未配置凭证时抛异常（由调用方留痕）
     */
    private function pushWecomMessage(int $userId, string $content, string $title): void
    {
        $corpId = (string) config('wecom.corp_id', '');
        $agentId = (string) config('wecom.agent_id', '');
        $secret = (string) config('wecom.secret', '');

        if ($corpId === '' || $agentId === '' || $secret === '') {
            throw new \RuntimeException('企业微信应用未配置（corp_id/agent_id/secret）');
        }

        $user = User::find($userId);
        if (! $user || ! $user->wecom_userid) {
            throw new \RuntimeException('用户未绑定企业微信 userid');
        }

        $tokenResp = Http::timeout(10)->get('https://qyapi.weixin.qq.com/cgi-bin/gettoken', [
            'corpid' => $corpId,
            'corpsecret' => $secret,
        ])->json();
        $accessToken = (string) data_get($tokenResp, 'access_token', '');
        if ($accessToken === '') {
            throw new \RuntimeException('获取企微 access_token 失败：'.mb_substr(json_encode($tokenResp, JSON_UNESCAPED_UNICODE), 0, 200));
        }

        $resp = Http::timeout(10)->post("https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token={$accessToken}", [
            'touser' => $user->wecom_userid,
            'msgtype' => 'text',
            'agentid' => (int) $agentId,
            'text' => ['content' => $title."\n".$content],
        ])->json();

        if ((int) data_get($resp, 'errcode', -1) !== 0) {
            throw new \RuntimeException('企微消息发送失败：'.mb_substr(json_encode($resp, JSON_UNESCAPED_UNICODE), 0, 200));
        }
    }
}
