<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 企业微信免密登录服务（一期骨架：接口已就绪，联调时填入真实配置）
 *
 * 流程（架构 §5.3）：企微扫码 → code → 换 userid → 匹配 users.wecom_userid → 签发 Token
 * 参考：https://developer.work.weixin.qq.com/document/path/91022
 */
class WecomAuthService
{
    /**
     * 是否已配置企微（未配置且未开模拟模式时返回 501 供前端回退账号密码）
     */
    public function isConfigured(): bool
    {
        return (bool) config('wecom.mock_mode', false)
            || (config('wecom.corp_id') !== '' && config('wecom.secret') !== '');
    }

    /**
     * 用 OAuth code 登录：换取 userid → 匹配用户
     *
     * @throws \RuntimeException 企微未配置或接口调用失败
     */
    public function loginByCode(string $code): ?User
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('企业微信未配置，请使用账号密码登录');
        }

        // 沙箱模拟模式（无真实 corp 凭证）：code 即绑定标识（bindEmployee 时写入 wecom_userid=code）
        // 【联调点】真实环境：设置 WECOM_MOCK_MODE=false 并配置 corp_id/secret，走 exchangeCodeForUserid 换真实 userid
        if ((bool) config('wecom.mock_mode', false)) {
            return User::where('wecom_userid', $code)->first();
        }

        $userid = $this->exchangeCodeForUserid($code);

        return User::where('wecom_userid', $userid)->first();
    }

    /**
     * code 换取 userid（两跳：code → access_token → userid）
     */
    public function exchangeCodeForUserid(string $code): string
    {
        $token = $this->getAccessToken();

        $response = Http::timeout(10)->get('https://qyapi.weixin.qq.com/cgi-bin/auth/getuserinfo', [
            'access_token' => $token,
            'code' => $code,
        ])->json();

        if (($response['errcode'] ?? -1) !== 0) {
            Log::error('企微 code 换 userid 失败', ['response' => $response]);
            throw new \RuntimeException('企微登录失败：'.$response['errmsg'] ?? '未知错误');
        }

        return $response['userid'];
    }

    /**
     * 获取企业 access_token（带缓存，2 小时有效期）
     */
    public function getAccessToken(): string
    {
        $cacheKey = 'wecom:access_token';

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 7200, function () {
            if (! $this->isConfigured()) {
                throw new \RuntimeException('企业微信未配置');
            }

            $response = Http::timeout(10)->get('https://qyapi.weixin.qq.com/cgi-bin/gettoken', [
                'corpid' => config('wecom.corp_id'),
                'corpsecret' => config('wecom.secret'),
            ])->json();

            if (($response['errcode'] ?? -1) !== 0) {
                Log::error('企微 access_token 获取失败', ['response' => $response]);
                throw new \RuntimeException('企微 access_token 获取失败：'.($response['errmsg'] ?? '未知错误'));
            }

            return $response['access_token'];
        });
    }
}