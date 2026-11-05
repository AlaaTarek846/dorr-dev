<?php

namespace Modules\WatchEarn\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Wallet\Support\Payments\CheckoutPurposes;
use Modules\WatchEarn\Console\WatchEarnTick;
use Modules\WatchEarn\Services\AdWalletTopupPurpose;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * DORR Watch & Earn (docs/watch_earn_tasks.md): advertisers pay per completed view of their image or video ad;
 * viewers earn a reward for watching it and one for interacting with it.
 */
class WatchEarnServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'WatchEarn';

    protected string $nameLower = 'watchearn';

    /**
     * @var string[]
     */
    protected array $commands = [
        WatchEarnTick::class,
    ];

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    /**
     * No views (API only) — registering the missing folder breaks `view:cache` on deploy.
     */
    protected function registerViews(): void {}

    public function boot(): void
    {
        parent::boot();

        // WE-013: the ad wallet is topped up on the app's one payment screen (card or wallet balance).
        $this->app->make(CheckoutPurposes::class)->register(AdWalletTopupPurpose::KEY, AdWalletTopupPurpose::class);
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(WatchEarnTick::class)->everyFiveMinutes()->withoutOverlapping();
    }
}
