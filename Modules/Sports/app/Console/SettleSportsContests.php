<?php

namespace Modules\Sports\Console;

use Illuminate\Console\Command;
use Modules\Sports\Services\ContestService;

/** Hourly: settle the contests whose matches are all over. */
class SettleSportsContests extends Command
{
    protected $signature = 'sports:contests';

    protected $description = 'Settle prediction contests whose matches are over';

    public function handle(ContestService $contests): int
    {
        $this->info('settled '.$contests->settleDue());

        return self::SUCCESS;
    }
}
