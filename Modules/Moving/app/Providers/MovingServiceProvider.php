<?php

namespace Modules\Moving\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Moving\Console\ExpireMovingRequests;
use Modules\Moving\Console\SettleMovingJobs;
use Modules\Moving\Events\MovingRequestChanged;
use Modules\Moving\Services\Notifications\MovingNotifier;
use Modules\Moving\Services\Payment\MovingChangeCheckoutPurpose;
use Modules\Moving\Services\Payment\MovingCheckoutPurpose;
use Modules\Moving\Services\Requests\MovingAssignmentService;
use Modules\Provider\Events\MembershipEnded;
use Modules\Wallet\Support\Payments\CheckoutPurposes;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * DORR furniture moving (docs/moving_tasks.md): customers post a move, provider establishments offer, the customer
 * accepts one and pays (held), the establishment assigns a driver and vehicle and carries it out.
 */
class MovingServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Moving';

    protected string $nameLower = 'moving';

    /** @var string[] */
    protected array $commands = [
        ExpireMovingRequests::class,
        SettleMovingJobs::class,
    ];

    /** @var string[] */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    /** No views (API only) — registering the missing folder breaks `view:cache` on deploy. */
    protected function registerViews(): void {}

    public function boot(): void
    {
        parent::boot();

        // B-06: accepting an offer is paid on the app's one payment screen and held until delivery.
        $this->app->make(CheckoutPurposes::class)->register(MovingCheckoutPurpose::KEY, MovingCheckoutPurpose::class);
        // B-13: the difference of an approved change order, held with the rest.
        $this->app->make(CheckoutPurposes::class)->register(MovingChangeCheckoutPurpose::KEY, MovingChangeCheckoutPurpose::class);

        // B-16: every step reloads the open screens once and tells who needs to know — after the change is saved.
        Event::listen(MovingRequestChanged::class, fn (MovingRequestChanged $e) => DB::afterCommit(fn () => app(MovingNotifier::class)->handle($e)));

        // §14: a driver who leaves hands their upcoming jobs back to the establishment.
        Event::listen(MembershipEnded::class, fn (MembershipEnded $e) => app(MovingAssignmentService::class)->releaseDriver($e->membership));
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(ExpireMovingRequests::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SettleMovingJobs::class)->everyFiveMinutes()->withoutOverlapping();
    }
}
