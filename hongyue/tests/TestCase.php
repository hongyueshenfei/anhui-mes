<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * 测试基类（Laravel 12：自动从 bootstrap/app.php 引导应用）
 *
 * 五期起全量用例增至 250+，单进程内存占用超过默认 128M（应用/容器/HTTP 响应对象累积），
 * 基类统一提升 memory_limit=512M，保证 `php artisan test` 默认 CLI 下全量可跑。
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ini_set('memory_limit', '512M');
    }
}
