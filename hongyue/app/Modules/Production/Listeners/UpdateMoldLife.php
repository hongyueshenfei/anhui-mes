<?php

namespace App\Modules\Production\Listeners;

use App\Modules\Production\Events\ReportSaved;
use App\Modules\Production\Services\MoldLifeService;

/**
 * 报工 → 模具寿命累计（开模次数 = 合格数 ÷ 模穴数）
 */
class UpdateMoldLife
{
    public function __construct(
        private readonly MoldLifeService $moldLifeService,
    ) {
    }

    public function handle(ReportSaved $event): void
    {
        $this->moldLifeService->addOpenings($event->task, $event->netGoodQty);
    }
}