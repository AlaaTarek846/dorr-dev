<?php

namespace Modules\Provider\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Moving\Models\MovingCase;
use Modules\Moving\Models\MovingTicket;
use Modules\Provider\Console\SweepProviderDocuments;
use Modules\Provider\Enums\EstablishmentStatus;
use Modules\Provider\Events\EstablishmentReviewed;
use Modules\Provider\Events\ProviderDocumentExpiring;
use Modules\Provider\Events\ProviderDocumentReviewed;
use Modules\Provider\Models\Establishment;
use Modules\Provider\Models\ProviderDocument;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\Provider\Services\Notifications\ProviderAdminNotifier;
use Modules\Provider\Services\Notifications\ProviderNotifier;
use Modules\Wallet\Support\WalletOwner;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ProviderServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Provider';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'provider';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        SweepProviderDocuments::class,
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

        // C-07: the providers app hears about reviews, papers about to end and invitations — after the change is saved.
        Event::listen(EstablishmentReviewed::class, fn ($e) => DB::afterCommit(fn () => app(ProviderNotifier::class)->establishmentReviewed($e)));
        Event::listen(ProviderDocumentReviewed::class, fn ($e) => DB::afterCommit(fn () => app(ProviderNotifier::class)->documentReviewed($e)));
        Event::listen(ProviderDocumentExpiring::class, fn ($e) => DB::afterCommit(fn () => app(ProviderNotifier::class)->documentExpiring($e)));

        // The dashboard hears about whatever waits for a person there (sent after the change is saved).
        Establishment::updated(function ($e) {
            if ($e->wasChanged('status') && $e->status === EstablishmentStatus::UnderReview) {
                app(ProviderAdminNotifier::class)->establishmentSubmitted($e);
            }
        });
        ProviderDocument::created(fn ($d) => app(ProviderAdminNotifier::class)->documentUploaded($d));
        Vehicle::saved(function ($v) {
            if ($v->wasRecentlyCreated || $v->wasChanged('status')) {
                app(ProviderAdminNotifier::class)->vehiclePending($v);
            }
        });
        MovingCase::created(fn ($c) => app(ProviderAdminNotifier::class)->movingCaseOpened($c));
        MovingTicket::saved(function ($t) {
            if ($t->status === 'escalated' && ($t->wasRecentlyCreated || $t->wasChanged('status'))) {
                app(ProviderAdminNotifier::class)->movingTicketEscalated($t);
            }
        });

        // In the providers app the wallet screens show the establishment's money (docs/moving_tasks.md A-10): seeing
        // it needs wallet.view, moving it (payout methods, withdrawals) wallet.withdraw. The PIN stays the person's.
        $this->app->make(WalletOwner::class)->register('provider', function (Request $request) {
            if (! $request->hasHeader(EstablishmentAccess::HEADER)) {
                return null;
            }
            $access = $this->app->make(EstablishmentAccess::class);
            $membership = $access->current($request);
            $access->authorize($membership, $request->isMethod('GET') ? 'wallet.view' : 'wallet.withdraw');

            return $membership->establishment;
        });
    }

    /**
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command('provider:sweep-documents')->dailyAt('03:10')->withoutOverlapping();
    }
}
