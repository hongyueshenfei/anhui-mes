<?php

/**
 * AI 计算服务配置（Python FastAPI，内部 Token 鉴权，不走公网暴露）
 */
return [
    // Python 服务地址（Docker Compose 内网别名 ai-service 或本机调试 127.0.0.1:8001）
    'service_url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),

    // 内部共享 Token（环境变量 AI_SERVICE_TOKEN，与 ai-service 侧配置一致）
    'service_token' => env('AI_SERVICE_TOKEN'),
];