<?php

/**
 * 小程序回写契约配置（架构 §5.2 W1 / 验收 D7）
 *
 * 小程序作为客户端调用平台进度查询 API 时使用 appKey + HMAC-SHA256 签名鉴权：
 *   Headers: X-App-Key / X-Timestamp / X-Signature
 *   Signature = base64( HMAC-SHA256( app_secret, "{timestamp}\n{METHOD}\n{path}" ) )
 *   时间戳偏差 ≤ 300s（防重放）；测试密钥见下方默认值。
 */
return [
    'app_key' => env('MP_APP_KEY', 'hy-mp-demo-key'),
    'app_secret' => env('MP_APP_SECRET', 'hy-mp-demo-secret'),
    'timestamp_skew_sec' => 300,
    // 进度回写（平台 → 小程序）目标端点（小程序开发方提供，未就绪时为 null → 仅留痕）
    'push_url' => env('MP_PUSH_URL', null),
];
