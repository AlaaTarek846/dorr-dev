<?php

namespace Modules\Sports\Console;

use Illuminate\Console\Command;
use Modules\Sports\Services\SportsEngine;

/** Every 30 seconds: the live loop. Costs no requests outside match windows. */
class SportsTick extends Command
{
    protected $signature = 'sports:tick';

    protected $description = 'Update live matches inside their windows, within the daily budget';

    public function handle(SportsEngine $engine): int
    {
        $r = $engine->tick();
        $this->info("polls {$r['polls']}, details {$r['details']}, changed {$r['changed']}");

        return self::SUCCESS;
    }
}
