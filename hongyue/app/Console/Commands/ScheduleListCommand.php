<?php

namespace App\Console\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduleListCommand as BaseScheduleListCommand;

/**
 * schedule:list 环境降级实现（P2-3）
 *
 * 框架版 ScheduleListCommand 渲染时会对每个事件调用 $event->mutex->exists()（缓存互斥锁状态），
 * 当默认缓存为 Redis 且 Redis 不可达（本地/CI 未起 Redis）时抛 Predis ConnectionException；
 * 该异常在非 TTY（管道/CI）下又会被 Collision 错误处理器以 null output 二次抛 TypeError，
 * 导致 artisan schedule:list 完全不可用（并非业务代码错误）。
 *
 * 本实现捕获环境异常后降级为「不含互斥状态」的纯任务摘要列表，保证
 * `php artisan schedule:list` 始终可正常查看定时任务。降级方案说明：
 *   生产环境配置 CACHE_STORE=redis + 正常运行的 Redis 时，走框架原生完整展示；
 *   本地无 Redis 时自动降级，不影响 schedule:run 实际调度（调度本身需要 Redis 时才校验互斥）。
 */
class ScheduleListCommand extends BaseScheduleListCommand
{
    public function handle(Schedule $schedule): void
    {
        try {
            parent::handle($schedule);
        } catch (\Throwable $e) {
            $this->components->warn(sprintf(
                'schedule:list 环境降级展示（%s）：互斥状态省略，任务摘要如下。',
                mb_substr($e->getMessage(), 0, 80)
            ));

            foreach ($schedule->events() as $event) {
                $this->line(sprintf(
                    '  <fg=yellow>%s</>  %s',
                    $event->expression,
                    $event->getSummaryForDisplay()
                ));
            }
        }
    }
}
