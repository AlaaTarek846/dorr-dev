<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Services\FreelancerTeam\TeamSubscriptions;
use Modules\Freelance\Services\Plans\FreelanceSubscriptions;

/**
 * Hourly (FL-L01): renews the plans whose period ended (the wallet pays the price of the day), retries the ones that failed (once a day,
 * through the grace days), ends the ones that will not renew or ran out of grace, and reminds who renews soon. FS-211: the same pass for the
 * teams' plans, which their Owner pays from his own wallet.
 */
class RenewFreelanceSubscriptions extends Command
{
    protected $signature = 'freelance:renew-subscriptions';

    protected $description = 'Renew, retry, end and remind freelancer plans (FL-L01) and team plans (FS-211)';

    public function handle(FreelanceSubscriptions $subscriptions, TeamSubscriptions $teams): int
    {
        $r = $subscriptions->run();
        $this->info("Renewed {$r['renewed']}, failed {$r['failed']}, ended {$r['ended']}, reminded {$r['reminded']}.");
        $t = $teams->run();
        $this->info("Teams: renewed {$t['renewed']}, failed {$t['failed']}, ended {$t['ended']}, reminded {$t['reminded']}.");

        return self::SUCCESS;
    }
}
