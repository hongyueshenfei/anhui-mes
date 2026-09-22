<?php

namespace App\Modules\Auth\Services;

use App\Models\User;

/**
 * 微信扫码免密登录服务（一期骨架）
 *
 * 流程（架构 §5.3）：微信扫码 → openid → 匹配 users.wx_openid → 免密；
 * 首次录入工号+姓名绑定（绑定动作在 AuthController::bindEmployee）。
 */
class WxAuthService
{
    /**
     * 用 openid 匹配用户（未绑定返回 null，前端引导绑工号）
     */
    public function loginByOpenid(string $openid): ?User
    {
        return User::where('wx_openid', $openid)->first();
    }

    /**
     * 预留：小程序登录凭证 code 换 openid（依赖 wx.app_id/app_secret）
     * 联调时调用；一期前端直接传 openid。
     *
     * @throws \RuntimeException 微信未配置
     */
    public function code2Openid(string $code): string
    {
        if (config('wecom.wx.app_id') === '') {
            throw new \RuntimeException('微信小程序未配置，请直传 openid');
        }

        $response = \Illuminate\Support\Facades\Http::timeout(10)->get(
            'https://api.weixin.qq.com/sns/jscode2session',
            [
                'appid' => config('wecom.wx.app_id'),
                'secret' => config('wecom.wx.app_secret'),
                'js_code' => $code,
                'grant_type' => 'authorization_code',
            ]
        )->json();

        return $response['openid'] ?? throw new \RuntimeException('微信 code 换 openid 失败：'.($response['errmsg'] ?? '未知错误'));
    }
}