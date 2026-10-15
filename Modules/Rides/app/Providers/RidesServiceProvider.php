<?php

namespace Modules\Rides\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\Driver\Contracts\DriverWorkGate;
use Modules\Driver\Events\DriverLocationUpdated;
use Modules\Rides\Console\DispatchTick;
use Modules\Rides\Console\ReleaseEarnings;
use Modules\Rides\Console\WaslEligibility;
use Modules\Rides\Console\WaslLocations;
use Modules\Rides\Console\WaslTrips;
use Modules\Rides\Listeners\HandleDriverLocation;
use Modules\Rides\Listeners\RelayDriverLocation;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Geo\GeoProvider;
use Modules\Rides\Services\Geo\ResilientGeoProvider;
use Modules\Rides\Services\Payment\RideCheckoutPurpose;
use Modules\Wallet\Support\Payments\CheckoutPurposes;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * DORR passenger rides (docs/rides-plan.md, docs/rides-structure.md): ride categories, feature
 * switches per country / city, policies and prices from the admin, and — from phase 1 — the
 * ride itself, dispatch and Wasl. The drivers themselves live in Modules/Driver.
 */
class RidesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Rides';

    protected string $nameLower = 'rides';

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
        DispatchTick::class,
        ReleaseEarnings::class,
        WaslTrips::class,
        WaslLocations::class,
        WaslEligibility::class,
    ];

    public function register(): void
    {
        parent::register();

        // Google first (owner's decision), Jawad's free estimate when Google can't answer.
        $this->app->singleton(GeoProvider::class, ResilientGeoProvider::class);

        // Whether a driver may go online is decided here (documents, vehicle, Wasl…), not in Modules/Driver.
        $this->app->bind(DriverWorkGate::class, DriverEligibility::class);
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(DriverLocationUpdated::class, RelayDriverLocation::class);
        // The airport queue and the restricted-area alert follow the captain's latest point (D-277, D-280, D-283).
        Event::listen(DriverLocationUpdated::class, HandleDriverLocation::class);

        // A card ride is paid on the app's one payment screen, which then holds the amount (ReservesFunds).
        $this->app->make(CheckoutPurposes::class)->register(RideCheckoutPurpose::KEY, RideCheckoutPurpose::class);
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        // Jawad's dispatch rounds are 10 s apart (`search.round_interval_s`).
        $schedule->command(DispatchTick::class)->everyTenSeconds()->withoutOverlapping();
        $schedule->command(ReleaseEarnings::class)->hourly()->withoutOverlapping();

        // Wasl (Jawad's schedule): trips and locations every minute, eligibility daily at 00:01 Riyadh.
        $schedule->command(WaslTrips::class)->everyMinute()->withoutOverlapping();
        $schedule->command(WaslLocations::class)->everyMinute()->withoutOverlapping();
        $schedule->command(WaslEligibility::class)->dailyAt('00:01')->timezone((string) config('rides.wasl.timezone', 'Asia/Riyadh'))->withoutOverlapping();
    }

    /**
     * No views (API only) — registering the missing folder breaks `view:cache` on deploy.
     */
    protected function registerViews(): void {}
}
