<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Offers\SpecialOffers;

/** Hourly: special offers past their time end, and a client is told once when one ends within a day (FL-L09). */
class SweepSpecialOffers extends Command
{
    protected $signature = 'freelance:sweep-special-offers';

    protected $description = 'End special offers past their time and remind clients of ones ending soon (FL-L09)';

    public function handle(SpecialOffers $offers): int
    {
        $done = $offers->sweep();
        $this->info("{$done['expired']} expired, {$done['reminded']} reminded.");

        return self::SUCCESS;
    }
}
