<?php

namespace App\Console\Commands;

use App\Modules\Integration\Services\K3ProdOrderPullService;
use Illuminate\Console\Command;

/**
 * 金蝶生产订单拉取（四期 K1）
 *
 * 用法：php artisan k3:pull-prod-orders
 * 定时：routes/console.php 每 10 分钟
 */
class PullProdOrdersCommand extends Command
{
    protected $signature = 'k3:pull-prod-orders';

    protected $description = '拉取金蝶已审核生产订单（增量游标 + 幂等落库）';

    public function handle(K3ProdOrderPullService $service): int
    {
        $result = $service->pull();

        $this->info($result['message']);

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
