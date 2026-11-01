<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Consultations\Consultations;

/** Every five minutes: remind both sides before a consultation, ask the client how it went, and pay out what nobody complained about (FL-L07). */
class SweepConsultations extends Command
{
    protected $signature = 'freelance:sweep-consultations';

    protected $description = 'Remind, ask after and pay out paid consultations (FL-L07)';

    public function handle(Consultations $consultations): int
    {
        $done = $consultations->sweep();
        $this->info("{$done['reminded']} reminded, {$done['asked']} asked, {$done['paid']} paid.");

        return self::SUCCESS;
    }
}
