<?php

namespace App\Console\Commands;

use App\Modules\Integration\Services\MaterialSyncService;
use Illuminate\Console\Command;

/**
 * 金蝶物料主数据同步（四期 K4）
 *
 * 用法：php artisan k3:sync-materials
 * 定时：routes/console.php 每日一次 + 手动触发
 */
class SyncMaterialsCommand extends Command
{
    protected $signature = 'k3:sync-materials';

    protected $description = '同步金蝶物料主数据到 materials 表（编码对齐 + 未匹配清单）';

    public function handle(MaterialSyncService $service): int
    {
        $result = $service->sync();

        $this->info($result['message']);

        foreach ($result['items'] as $item) {
            $this->warn(sprintf('  ⚠ %s · %s', $item['code'], $item['name']));
        }

        return self::SUCCESS;
    }
}
