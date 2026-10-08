<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\AI\Services\Sites\AiSiteHostingService;

class ProcessAiSiteHostings extends Command
{
    protected $signature = 'ai:process-site-hostings';

    protected $description = 'Renew due site hostings from the wallet, start grace periods, suspend lapsed sites and release long-suspended names';

    public function handle(AiSiteHostingService $hostings): int
    {
        $stats = $hostings->processDue();

        $this->info(sprintf('renewed=%d grace=%d suspended=%d deleted=%d', $stats['renewed'], $stats['grace'], $stats['suspended'], $stats['deleted']));

        return self::SUCCESS;
    }
}
