<?php

namespace Modules\Freelance\Console;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\Freelance\Services\Reports\FreelanceMoneyReport;

/**
 * Daily (FL-512): counts the project money from two places; when they differ, the staff who read the reports are told — at most
 * once a day. Nothing is fixed by itself.
 */
class ReconcileFreelanceMoney extends Command
{
    protected $signature = 'freelance:reconcile';

    protected $description = 'Check the project money against the wallets, the ledger and the receipts, and tell staff of any difference (FL-512)';

    public function handle(FreelanceMoneyReport $report, NotificationCenter $center): int
    {
        $issues = $report->reconcile();
        $this->info(count($issues).' difference(s).');
        if ($issues === [] || ! Cache::add('freelance:reconcile:told:'.now()->toDateString(), true, now()->endOfDay())) {
            return self::SUCCESS;
        }
        $admins = $center->adminsWith('freelance-reports.view');
        if ($admins->isNotEmpty()) {
            $center->send($admins, 'freelance.reconcile.differences', 'freelance_admin_reconcile_title', 'freelance_admin_reconcile_body',
                ['count' => count($issues)], ['type' => 'freelance_reconcile'], push: false);
        }

        return self::SUCCESS;
    }
}
