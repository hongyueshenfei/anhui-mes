<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'code' => 0,
        'message' => '虹越智造平台 API 正常运行（一期：工程骨架 + 数据底座）',
        'data' => [
            'docs' => '/api/health',
        ],
    ]);
});