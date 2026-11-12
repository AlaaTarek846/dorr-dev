<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Projects\Agreements;

/** Hourly: an agreement the freelancer left unanswered ends, and the client may choose again (FL-401). */
class ExpireAgreements extends Command
{
    protected $signature = 'freelance:expire-agreements';

    protected $description = 'End the agreements left unanswered past their time (FL-401)';

    public function handle(Agreements $agreements): int
    {
        $this->info($agreements->expireDue().' agreement(s) expired.');

        return self::SUCCESS;
    }
}
