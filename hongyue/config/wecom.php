<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 企业微信应用配置（一期骨架：扫码登录为接口骨架，联调时填入真实值）
    |--------------------------------------------------------------------------
    | 参考：https://developer.work.weixin.qq.com/document/path/91022
    */
    'corp_id' => env('WECOM_CORP_ID', ''),

    'agent_id' => env('WECOM_AGENT_ID', ''),

    'secret' => env('WECOM_SECRET', ''),

    // OAuth 回调地址（redirect_uri）
    'oauth_redirect_uri' => env('WECOM_OAUTH_REDIRECT_URI', ''),

    /*
    |--------------------------------------------------------------------------
    | 沙箱模拟模式（三期：无真实 corp 凭证时走通扫码流程）
    |--------------------------------------------------------------------------
    | true：wecom-login 的 code 直接作为绑定标识匹配 users.wecom_userid（bindEmployee 写入）。
    | 默认 false（保持未配置 501 回退行为）；演示/沙箱环境 .env 设置 WECOM_MOCK_MODE=true。
    | 联调点：获得真实企微凭证后保持 false 并配置 corp_id/secret/agent_id。
    */
    'mock_mode' => env('WECOM_MOCK_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | 微信小程序配置（一期骨架）
    |--------------------------------------------------------------------------
    */
    'wx' => [
        'app_id' => env('WX_APP_ID', ''),
        'app_secret' => env('WX_APP_SECRET', ''),
    ],

];