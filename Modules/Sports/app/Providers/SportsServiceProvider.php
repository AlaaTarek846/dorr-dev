<?php

namespace Modules\Sports\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\Sports\Console\CheckSportsProvider;
use Modules\Sports\Console\ImportSportsCompetitions;
use Modules\Sports\Console\SendSportsReminders;
use Modules\Sports\Console\SettleSportsContests;
use Modules\Sports\Console\SportsTick;
use Modules\Sports\Console\SyncSportsSchedules;
use Modules\Sports\Console\SportsPlanChanged;
use Modules\Sports\Console\SyncSportsSeasons;
use Modules\Sports\Console\SyncSportsStandings;
use Modules\Sports\Events\MatchChanged;
use Modules\Sports\Listeners\SendSportsAlerts;
use Modules\Sports\Listeners\SettlePredictions;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * DORR Sports (spec 183–200, docs/sports-plan.md): fixtures, live scores and standings come only
 * from a licensed provider (API-Sports), synced and cached on the server within one daily budget —
 * the apps never call it, and the AI never guesses live data (200).
 */
class SportsServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Sports';

    protected string $nameLower = 'sports';

    /**
     * @var string[]
     */
    protected array $commands = [
        CheckSportsProvider::class,
        ImportSportsCompetitions::class,
        SportsTick::class,
        SyncSportsSchedules::class,
        SyncSportsStandings::class,
        SyncSportsSeasons::class,
        SportsPlanChanged::class,
        SendSportsReminders::class,
        SettleSportsContests::class,
    ];

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();
        Event::listen(MatchChanged::class, SendSportsAlerts::class);
        Event::listen(MatchChanged::class, SettlePredictions::class);
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(SportsTick::class)->everyThirtySeconds()->withoutOverlapping(5);
        $schedule->command(SyncSportsSchedules::class)->hourly()->withoutOverlapping();
        $schedule->command(SyncSportsStandings::class)->hourlyAt(20)->withoutOverlapping();
        $schedule->command(SyncSportsSeasons::class)->hourlyAt(50)->withoutOverlapping();
        $schedule->command(SendSportsReminders::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SettleSportsContests::class)->hourlyAt(40)->withoutOverlapping();
    }
}
