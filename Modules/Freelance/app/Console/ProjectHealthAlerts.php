<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Projects\ProjectHealth;

/** Hourly (FS-106): tells who has to act when a running project gets at risk, delayed or blocked - once per setback. */
class ProjectHealthAlerts extends Command
{
    protected $signature = 'freelance:health-alerts';

    protected $description = 'Warn about running projects that got at risk, delayed or blocked (FS-106)';

    public function handle(ProjectHealth $health): int
    {
        $this->info($health->alertWorse().' alerted.');

        return self::SUCCESS;
    }
}
