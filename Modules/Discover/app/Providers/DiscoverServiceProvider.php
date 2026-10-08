<?php

namespace Modules\Discover\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Discover\Console\CloseEventRooms;
use Modules\Discover\Console\SendDiscoverAlerts;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * DORR Discover (spec 169–182): public events from trusted sources — verified organizers and the
 * admin — by city, interest and date; saved into DORR Calendar, shared into chats.
 */
class DiscoverServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Discover';

    protected string $nameLower = 'discover';

    /**
     * @var string[]
     */
    protected array $commands = [
        SendDiscoverAlerts::class,
        CloseEventRooms::class,
    ];

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(SendDiscoverAlerts::class)->hourly()->withoutOverlapping();
        $schedule->command(CloseEventRooms::class)->everyThirtyMinutes()->withoutOverlapping();
    }
}
