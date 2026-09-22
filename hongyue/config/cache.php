<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | 默认缓存存储
    |--------------------------------------------------------------------------
    */
    'default' => env('CACHE_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | 缓存存储
    |--------------------------------------------------------------------------
    */
    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | 缓存键前缀
    |--------------------------------------------------------------------------
    */
    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'hongyue'), '_').'_cache_'),

];