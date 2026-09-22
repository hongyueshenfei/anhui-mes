<?php

use App\Modules\Integration\Jobs\PushProgressJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/*
|--------------------------------------------------------------------------
| 四期定时任务（验收 D4/D6/D7）
|--------------------------------------------------------------------------
| - k3:pull-prod-orders  每 10 分钟拉取金蝶生产订单（增量游标 + 幂等）
| - k3:sync-materials    每日 03:00 物料主数据同步（另支持手动触发）
| - push-progress        每 30 分钟小程序进度回写兜底（端点未配置时仅留痕）
| 注意：php artisan schedule:run 需由系统 cron 每分钟执行。
*/
Schedule::command('k3:pull-prod-orders')->everyTenMinutes()->withoutOverlapping();
Schedule::command('k3:sync-materials')->dailyAt('03:00')->withoutOverlapping();
Schedule::job(new PushProgressJob)->everyThirtyMinutes()->withoutOverlapping();
