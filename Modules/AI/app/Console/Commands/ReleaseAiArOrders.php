<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Services\Ar\AiArOrderService;

/** Pays out delivered AR design orders whose confirm/dispute window has run out. */
class ReleaseAiArOrders extends Command
{
    protected $signature = 'ai:release-ar-orders';

    protected $description = 'Release escrow for delivered AR design orders past their window';

    public function handle(AiArOrderService $orders): int
    {
        $this->info('released='.$orders->releaseDue());

        return self::SUCCESS;
    }
}
