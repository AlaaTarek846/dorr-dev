<?php

namespace Modules\Sports\Console;

use Illuminate\Console\Command;
use Modules\Sports\Services\SportsEngine;

/** Hourly, a few at a time: each active competition's whole season once a day (minor: weekly). */
class SyncSportsSeasons extends Command
{
    protected $signature = 'sports:seasons';

    protected $description = 'Sync the season schedules that are due (every round of each competition)';

    public function handle(SportsEngine $engine): int
    {
        foreach ($engine->syncDueSeasons() as $name => $rows) {
            $this->line("{$name}: {$rows}");
        }

        return self::SUCCESS;
    }
}
