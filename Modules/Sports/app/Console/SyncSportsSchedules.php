<?php

namespace Modules\Sports\Console;

use Illuminate\Console\Command;
use Modules\Sports\Services\SportsEngine;

/** Hourly: the schedules that are due (today and tomorrow every 3 hours, the week once a day). */
class SyncSportsSchedules extends Command
{
    protected $signature = 'sports:schedule';

    protected $description = 'Sync the match schedules that are due';

    public function handle(SportsEngine $engine): int
    {
        foreach ($engine->syncDueSchedules() as $what => $count) {
            $this->line("{$what}: {$count}");
        }

        return self::SUCCESS;
    }
}
