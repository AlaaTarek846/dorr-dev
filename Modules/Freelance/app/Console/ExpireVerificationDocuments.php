<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Verification\FreelancerVerification;

/** Daily: approved identity documents past their date expire, and a verified freelancer missing one goes back to fixing it. */
class ExpireVerificationDocuments extends Command
{
    protected $signature = 'freelance:expire-documents';

    protected $description = 'Expire freelancer identity documents past their date (FL-107)';

    public function handle(FreelancerVerification $verification): int
    {
        $this->info($verification->expireDocuments().' document(s) expired.');

        return self::SUCCESS;
    }
}
