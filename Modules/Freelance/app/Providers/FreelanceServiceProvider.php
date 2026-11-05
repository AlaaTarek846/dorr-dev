<?php

namespace Modules\Freelance\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Freelance\Console\CloseExpiredProjectOffers;
use Modules\Freelance\Console\ExpireAgreements;
use Modules\Freelance\Console\ReleaseDueMilestones;
use Modules\Freelance\Console\ExecuteDisputeDecisions;
use Modules\Freelance\Console\ReleaseFreelanceEarnings;
use Modules\Freelance\Console\PublishFreelanceReviews;
use Modules\Freelance\Console\ReconcileFreelanceMoney;
use Modules\Freelance\Console\RenewFreelanceSubscriptions;
use Modules\Freelance\Console\TellFreelanceLateWork;
use Modules\Freelance\Console\RemindFreelanceInterviews;
use Modules\Freelance\Console\RefreshFreelancerRisk;
use Modules\Freelance\Console\ProjectHealthAlerts;
use Modules\Freelance\Console\RemindChangeRequests;
use Modules\Freelance\Console\RemindTalentPool;
use Modules\Freelance\Console\RemindProgressReports;
use Modules\Freelance\Console\ExpireVerificationDocuments;
use Illuminate\Support\Facades\Event;
use Modules\Freelance\Events\FreelanceSkillsMerged;
use Modules\Freelance\Services\Account\FreelancerProfileLists;
use Modules\Freelance\Models\Freelancer;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * DORR freelance (docs/freelance_tasks.md): a freelancer is an independent identity (own guard,
 * wallet and chat, like a driver); the client is a regular Dorr user. Admin, user app and freelancer app.
 */
class FreelanceServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Freelance';

    protected string $nameLower = 'freelance';

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    /**
     * @var string[]
     */
    protected array $commands = [
        ExpireVerificationDocuments::class,
        CloseExpiredProjectOffers::class,
        ExpireAgreements::class,
        ReleaseDueMilestones::class,
        ExecuteDisputeDecisions::class,
        ReleaseFreelanceEarnings::class,
        PublishFreelanceReviews::class,
        ReconcileFreelanceMoney::class,
        TellFreelanceLateWork::class,
        RemindFreelanceInterviews::class,
        RemindProgressReports::class,
        RemindChangeRequests::class,
        ProjectHealthAlerts::class,
        RemindTalentPool::class,
        RefreshFreelancerRisk::class,
        RenewFreelanceSubscriptions::class,
        \Modules\Freelance\Console\SweepFreelanceWaitlist::class,
        \Modules\Freelance\Console\SweepServiceSubscriptions::class,
        \Modules\Freelance\Console\SweepConsultations::class,
        \Modules\Freelance\Console\SyncConsultationSessions::class,
        \Modules\Freelance\Console\SweepSpecialOffers::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // One currency and one international wallet for all freelance money (`freelance.money`); the row is looked up once a request.
        $this->app->scoped(\Modules\Freelance\Services\Policy\FreelanceMoney::class);

        // Same non-enforced alias as 'user' / 'driver': tokens, OTP codes and media store 'freelancer'.
        Relation::morphMap(['freelancer' => Freelancer::class]);

        // A duplicate skill merged in the catalog (FL-102): whoever listed it now lists the survivor.
        Event::listen(FreelanceSkillsMerged::class, fn (FreelanceSkillsMerged $e) => $this->app->make(FreelancerProfileLists::class)->moveMergedSkill($e->sourceId, $e->targetId));

        // FL-L10: work that stops running frees a place — the first in line for that freelancer is offered it (after the change is saved).
        \Modules\Freelance\Models\FreelanceAgreement::updated(function (\Modules\Freelance\Models\FreelanceAgreement $a) {
            // FL-L05: the first month of a subscription accepted starts it; never started, it ends.
            if ($a->wasChanged('status')) {
                $this->app->make(\Modules\Freelance\Services\Market\ServiceSubscriptions::class)->agreementChanged($a);
            }
            if ($a->wasChanged('status') && $a->getOriginal('status') === \Modules\Freelance\Models\FreelanceAgreement::ACTIVE) {
                $id = (int) $a->freelancer_id;
                \Illuminate\Support\Facades\DB::afterCommit(fn () => $this->app->make(\Modules\Freelance\Services\Market\FreelanceCapacity::class)->promote($id));
            }
            // FL-L09 (part B): a finished work can take the client to the next step of the freelancer's loyalty program.
            if ($a->wasChanged('status') && $a->status === \Modules\Freelance\Models\FreelanceAgreement::COMPLETED) {
                $this->tellLoyalty((int) $a->freelancer_id, (int) $a->client_id);
            }
        });
        \Modules\Freelance\Models\FreelanceConsultation::updated(function (\Modules\Freelance\Models\FreelanceConsultation $c) {
            if ($c->wasChanged('status') && $c->status === \Modules\Freelance\Models\FreelanceConsultation::COMPLETED) {
                $this->tellLoyalty((int) $c->freelancer_id, (int) $c->client_id);
            }
        });
    }

    /** After the change is saved: the client is told if this work took them to a step of the freelancer's loyalty program. */
    private function tellLoyalty(int $freelancerId, int $clientId): void
    {
        \Illuminate\Support\Facades\DB::afterCommit(fn () => rescue(
            fn () => $this->app->make(\Modules\Freelance\Services\Offers\Loyalty::class)->unlocked($freelancerId, $clientId),
        ));
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        // Identity documents past their date (FL-107).
        $schedule->command(ExpireVerificationDocuments::class)->dailyAt('02:10')->withoutOverlapping();
        $schedule->command(CloseExpiredProjectOffers::class)->hourly()->withoutOverlapping();
        $schedule->command(ExpireAgreements::class)->hourly()->withoutOverlapping();
        $schedule->command(ReleaseDueMilestones::class)->hourly()->withoutOverlapping();
        $schedule->command(ExecuteDisputeDecisions::class)->hourly()->withoutOverlapping();
        $schedule->command(ReleaseFreelanceEarnings::class)->hourly()->withoutOverlapping();
        $schedule->command(PublishFreelanceReviews::class)->hourly()->withoutOverlapping();
        $schedule->command(ReconcileFreelanceMoney::class)->dailyAt('03:20')->withoutOverlapping();
        $schedule->command(TellFreelanceLateWork::class)->dailyAt('08:00')->withoutOverlapping();
        $schedule->command(RemindFreelanceInterviews::class)->everyMinute()->withoutOverlapping();
        $schedule->command(RemindProgressReports::class)->hourly()->withoutOverlapping();
        $schedule->command(RemindChangeRequests::class)->hourly()->withoutOverlapping();
        // FS-106: a running project that got at risk, delayed or blocked.
        $schedule->command(ProjectHealthAlerts::class)->hourly()->withoutOverlapping();
        // FS-103: a client's own "hire again" reminders.
        $schedule->command(RemindTalentPool::class)->hourly()->withoutOverlapping();
        // FL-707: the quality score and risk level shown to staff.
        $schedule->command(RefreshFreelancerRisk::class)->dailyAt('04:10')->withoutOverlapping();
        // FL-L01: freelancer plans renew, retry and end.
        $schedule->command(RenewFreelanceSubscriptions::class)->hourly()->withoutOverlapping();
        // FL-L10: a place kept for the first in line and left unused goes to the next.
        $schedule->command(\Modules\Freelance\Console\SweepFreelanceWaitlist::class)->everyFiveMinutes()->withoutOverlapping();
        $schedule->command(\Modules\Freelance\Console\SweepServiceSubscriptions::class)->hourly()->withoutOverlapping();
        // FL-L07: reminders a little before, the question at the end, and paying out after the complaint hours.
        $schedule->command(\Modules\Freelance\Console\SweepConsultations::class)->everyFiveMinutes()->withoutOverlapping();
        // FL-L16: video sessions - warn before the end, close at the end, follow / move the recordings home, delete them a week on.
        $schedule->command(\Modules\Freelance\Console\SyncConsultationSessions::class)->everyMinute()->withoutOverlapping();
        // FL-L09: special offers past their time end, and the day-before reminder.
        $schedule->command(\Modules\Freelance\Console\SweepSpecialOffers::class)->hourly()->withoutOverlapping();
    }

    /**
     * No views (API only) — registering the missing folder breaks `view:cache` on deploy.
     */
    protected function registerViews(): void {}
}
