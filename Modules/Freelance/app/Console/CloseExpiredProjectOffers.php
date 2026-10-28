<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Projects\ProjectOffersWindow;

/** Hourly: a published project whose time for offers is up stops taking them, and its client is told (FL-204). */
class CloseExpiredProjectOffers extends Command
{
    protected $signature = 'freelance:close-expired-projects';

    protected $description = 'Close the offers of projects whose deadline passed (FL-204)';

    public function handle(ProjectOffersWindow $window): int
    {
        $this->info($window->closeExpired().' project(s) closed to offers.');

        return self::SUCCESS;
    }
}
