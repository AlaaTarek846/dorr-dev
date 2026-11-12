<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Modules\Freelance\Models\FreelanceCrmContact;
use Modules\Freelance\Models\FreelanceCrmReminder as Reminder;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Plans\FreelancePerks;

/**
 * FS-703: tells a freelancer once when a follow-up reminder of the CRM comes due. A freelancer whose plan no longer has reminders is not told
 * (the reminder is marked told, so it does not wake up later when they come back). Nothing else is changed.
 */
class NotifyCrmReminders extends Command
{
    protected $signature = 'freelance:crm-reminders';

    protected $description = 'Tell freelancers their CRM follow-up reminders that are due (FS-703)';

    public function handle(FreelanceNotifier $notifier, FreelancePerks $perks): int
    {
        $told = 0;
        Reminder::query()->whereNull('done_at')->whereNull('notified_at')->where('due_at', '<=', now())->orderBy('id')->limit(500)->with('freelancer')->get()
            ->each(function (Reminder $r) use ($notifier, $perks, &$told) {
                $r->update(['notified_at' => now()]);
                $f = $r->freelancer;
                if ($f === null || $perks->crmLevel($f) < 2) {
                    return;
                }
                $contact = FreelanceCrmContact::query()->where('freelancer_id', $r->freelancer_id)->where('user_id', $r->user_id)->with('client:id,name')->first();
                $notifier->notify($f, 'crm.reminder_due', ['client' => (string) $contact?->client?->name, 'title' => $r->title], ['type' => 'crm_reminder', 'client' => $contact?->uuid]);
                $told++;
            });
        $this->info($told.' reminder(s) told.');

        return self::SUCCESS;
    }
}
