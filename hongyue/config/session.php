<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | 默认会话驱动
    |--------------------------------------------------------------------------
    */
    'driver' => env('SESSION_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | 会话生命周期（分钟）
    |--------------------------------------------------------------------------
    */
    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),

    'encrypt' => env('SESSION_ENCRYPT', false),

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => env('SESSION_TABLE', 'sessions'),

    'store' => env('SESSION_STORE'),

    /*
    |--------------------------------------------------------------------------
    | 会话 Cookie
    |--------------------------------------------------------------------------
    */
    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug(env('APP_NAME', 'hongyue'), '_').'_session'
    ),

    'path' => env('SESSION_PATH', '/'),

    'domain' => env('SESSION_DOMAIN'),

    'secure' => env('SESSION_SECURE_COOKIE'),

    'http_only' => env('SESSION_HTTP_ONLY', true),

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    /*
    |--------------------------------------------------------------------------
    | 分区 Cookie（CHIPS）
    |--------------------------------------------------------------------------
    */
    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

];