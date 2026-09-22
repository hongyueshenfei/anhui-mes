<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * 应用服务提供者
 * - 一期：无额外注册
 * - 二期：AiServiceClient/K3Client 等单例在此绑定
 * - 三期：报工联动事件显式注册（Events/Listeners 位于业务模块目录，不走自动发现）
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 排产 AI 客户端（可测试注入替换）
        $this->app->singleton(\App\Services\AiServiceClient::class);

        // 金蝶客户端（config/k3.php）
        $this->app->singleton(\App\Modules\Integration\Services\K3Client::class);
    }

    public function boot(): void
    {
        // 测试期间固定业务时间，避免排产/延迟/冻结窗口随着真实时间推进。
        $testNow = config('app.test_now');
        Carbon::setTestNow(
            is_string($testNow) && trim($testNow) !== ''
                ? Carbon::parse($testNow, config('app.timezone'))
                : null,
        );

        // 三期：报工 → 任务进度 + 模具寿命联动
        Event::listen(
            \App\Modules\Production\Events\ReportSaved::class,
            \App\Modules\Production\Listeners\UpdateTaskProgress::class,
        );
        Event::listen(
            \App\Modules\Production\Events\ReportSaved::class,
            \App\Modules\Production\Listeners\UpdateMoldLife::class,
        );
        // APS一期：报工 → forecast 滚动 + actual 回填（含冻结区防护）
        Event::listen(
            \App\Modules\Production\Events\ReportSaved::class,
            \App\Modules\Scheduling\Listeners\UpdateForecast::class,
        );
    }
}
