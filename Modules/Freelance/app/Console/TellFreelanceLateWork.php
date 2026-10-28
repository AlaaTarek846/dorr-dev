<?php

namespace Modules\Freelance\Console;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Modules\Freelance\Services\Reports\FreelanceLateWork;

/** Daily (FL-802): when something is late, the staff who watch the projects are told once that day. Nothing is changed. */
class TellFreelanceLateWork extends Command
{
    protected $signature = 'freelance:late-alerts';

    protected $description = 'Tell staff what is late in freelance work (FL-802)';

    public function handle(FreelanceLateWork $late, NotificationCenter $center): int
    {
        $total = array_sum(array_map('count', $late->groups()));
        $this->info($total.' late item(s).');
        if ($total === 0 || ! Cache::add('freelance:late:told:'.now()->toDateString(), true, now()->endOfDay())) {
            return self::SUCCESS;
        }
        $admins = $center->adminsWith('freelance-projects.view');
        if ($admins->isNotEmpty()) {
            $center->send($admins, 'freelance.late.work', 'freelance_admin_late_title', 'freelance_admin_late_body', ['count' => $total], ['type' => 'freelance_late'], push: false);
        }

        return self::SUCCESS;
    }
}
