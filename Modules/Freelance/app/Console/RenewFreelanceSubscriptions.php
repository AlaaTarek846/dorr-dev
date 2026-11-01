<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\Plans\FreelanceSubscriptions;

/**
 * Hourly (FL-L01): renews the plans whose period ended (the wallet pays the price of the day), retries the ones that failed (once a day,
 * through the grace days), ends the ones that will not renew or ran out of grace, and reminds who renews soon.
 */
class RenewFreelanceSubscriptions extends Command
{
    protected $signature = 'freelance:renew-subscriptions';

    protected $description = 'Renew, retry, end and remind freelancer plans (FL-L01)';

    public function handle(FreelanceSubscriptions $subscriptions): int
    {
        $r = $subscriptions->run();
        $this->info("Renewed {$r['renewed']}, failed {$r['failed']}, ended {$r['ended']}, reminded {$r['reminded']}.");

        return self::SUCCESS;
    }
}
