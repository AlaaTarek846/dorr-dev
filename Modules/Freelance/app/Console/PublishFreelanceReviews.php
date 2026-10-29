<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Projects\FreelanceReviews;

/** Hourly: the ratings of projects whose rating days are over show, even when only one side rated (FL-701). */
class PublishFreelanceReviews extends Command
{
    protected $signature = 'freelance:publish-reviews';

    protected $description = 'Show the project ratings whose rating time is over (FL-701)';

    public function handle(FreelanceReviews $reviews): int
    {
        $this->info($reviews->publishDue().' rating(s) shown.');

        return self::SUCCESS;
    }
}
