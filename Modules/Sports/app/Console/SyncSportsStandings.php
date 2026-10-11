<?php

namespace Modules\Sports\Console;

use Illuminate\Console\Command;
use Modules\Sports\Services\SportsEngine;

/** Hourly: standings after a round, else daily (weekly for minor competitions), top scorers daily. */
class SyncSportsStandings extends Command
{
    protected $signature = 'sports:standings';

    protected $description = 'Sync the standings that are due';

    public function handle(SportsEngine $engine): int
    {
        foreach ($engine->syncDueStandings() as $name => $rows) {
            $this->line("{$name}: {$rows}");
        }

        return self::SUCCESS;
    }
}
