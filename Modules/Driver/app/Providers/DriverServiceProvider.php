<?php

namespace Modules\Driver\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\Driver\Console\SweepDriverPresence;
use Modules\Driver\Contracts\DriverWorkGate;
use Modules\Driver\Contracts\NoWorkGate;
use Modules\Driver\Models\Driver;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * DORR drivers (docs/rides-plan.md §4.1): their own account on the `driver_api` guard and
 * their own app — not a Provider. Documents, vehicles, presence and the location log live
 * here and are shared by every DORR service a driver works in; everything about rides lives
 * in Modules/Rides (which binds the DriverWorkGate).
 */
class DriverServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Driver';

    protected string $nameLower = 'driver';

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
        SweepDriverPresence::class,
    ];

    public function register(): void
    {
        parent::register();

        // Modules/Rides binds the real gate; this is only the fallback.
        $this->app->bindIf(DriverWorkGate::class, NoWorkGate::class);
    }

    public function boot(): void
    {
        parent::boot();

        // Same non-enforced alias as 'user' / 'provider' (Modules\AI\Providers\AIServiceProvider):
        // tokens, OTP codes and notification devices store 'driver', not the class name. A new
        // model, so no stored rows change.
        Relation::morphMap(['driver' => Driver::class]);
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(SweepDriverPresence::class)->everyMinute()->withoutOverlapping();
    }

    /**
     * No views (API only) — registering the missing folder breaks `view:cache` on deploy.
     */
    protected function registerViews(): void {}
}
