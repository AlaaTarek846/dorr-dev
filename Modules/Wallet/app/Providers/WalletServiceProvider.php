<?php

namespace Modules\Wallet\Providers;

use App\Models\Country;
use Illuminate\Console\Scheduling\Schedule;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Modules\Wallet\Console\ExpireStalePayments;
use Modules\Wallet\Console\ReconcileWallets;
use Modules\Wallet\Observers\WalletSettingObserver;

class WalletServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Wallet';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'wallet';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ReconcileWallets::class,
        ExpireStalePayments::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // No Relation::morphMap() call here on purpose — see
        // Modules\Wallet\Support\OwnerType's docblock.
        Country::observe(WalletSettingObserver::class);
    }

    /**
     * Define module schedules.
     *
     * @param $schedule
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(ReconcileWallets::class)->hourly()->withoutOverlapping();
        $schedule->command(ExpireStalePayments::class)->everyFiveMinutes()->withoutOverlapping();
    }
}
