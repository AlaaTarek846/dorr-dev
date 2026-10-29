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
use Modules\Freelance\Console\TellFreelanceLateWork;
use Modules\Freelance\Console\RemindFreelanceInterviews;
use Modules\Freelance\Console\RefreshFreelancerRisk;
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
        RefreshFreelancerRisk::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // Same non-enforced alias as 'user' / 'driver': tokens, OTP codes and media store 'freelancer'.
        Relation::morphMap(['freelancer' => Freelancer::class]);

        // A duplicate skill merged in the catalog (FL-102): whoever listed it now lists the survivor.
        Event::listen(FreelanceSkillsMerged::class, fn (FreelanceSkillsMerged $e) => $this->app->make(FreelancerProfileLists::class)->moveMergedSkill($e->sourceId, $e->targetId));
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
        // FL-707: the quality score and risk level shown to staff.
        $schedule->command(RefreshFreelancerRisk::class)->dailyAt('04:10')->withoutOverlapping();
    }

    /**
     * No views (API only) — registering the missing folder breaks `view:cache` on deploy.
     */
    protected function registerViews(): void {}
}
