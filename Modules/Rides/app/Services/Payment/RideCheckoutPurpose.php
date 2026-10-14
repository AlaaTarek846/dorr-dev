<?php

namespace Modules\Rides\Services\Payment;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Rides\Enums\BookingMode;
use Modules\Rides\Enums\RidePaymentStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Lifecycle\RideStateMachine;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Scheduling\ScheduledRideService;
use Modules\User\Models\User;
use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Contracts\ReservesFunds;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Support\Payments\CheckoutLine;

/**
 * A card ride on the app's one payment screen (owner: the payment screen is shared by every
 * service). Paying — from the balance or a gateway top-up — only makes sure the amount is in the
 * rider's wallet; fulfil() then holds it like a wallet ride and starts the search. Nothing is
 * debited until the ride ends (ReservesFunds).
 */
class RideCheckoutPurpose implements CheckoutPurpose, ReservesFunds
{
    public const KEY = 'ride';

    public function __construct(
        private readonly RidePaymentService $payments,
        private readonly RideStateMachine $machine,
        private readonly RideNotifier $notifier,
    ) {}

    public function line(Model $owner, Country $country, array $reference): CheckoutLine
    {
        $ride = $this->ride($owner, (string) ($reference['ride_uuid'] ?? ''));
        $ride->loadMissing('category.translations');

        return new CheckoutLine(
            (int) $ride->snapshot('hold_minor', $ride->agreed_price_minor ?? 0),
            __('rides.checkout.title', ['number' => $ride->number]),
            $ride->category?->translated('name'),
            ['ride_uuid' => $ride->uuid],
        );
    }

    public function fulfil(Checkout $checkout): void
    {
        $ride = Ride::query()->where('uuid', $checkout->reference['ride_uuid'] ?? null)->lockForUpdate()->first();
        if ($ride === null || $ride->status !== RideStatus::AwaitingPayment) {
            // Cancelled or expired meanwhile: the checkout fails and the money stays in the wallet.
            throw new RidesException('ride_not_awaiting_payment', 409);
        }

        $this->payments->hold($ride, (int) $checkout->amount_minor);
        $scheduled = $ride->booking_mode === BookingMode::Scheduled;
        $ride = $this->machine->move($ride, match ($ride->booking_mode) {
            BookingMode::Bid => RideStatus::Bidding,
            // Booked ahead: paid now, it waits for its time (ScheduledRideService).
            BookingMode::Scheduled => RideStatus::Scheduled,
            default => RideStatus::Searching,
        }, 'paid', 'user', $ride->user_id, [
            'payment_status' => RidePaymentStatus::Held,
            // The search clock starts with the payment, not with the booking.
            'search_started_at' => $scheduled ? null : now(),
            'next_dispatch_at' => $scheduled ? $ride->scheduled_at->copy()->subSeconds((int) $ride->policy('scheduled.lead_time_s', 1800)) : now(),
        ], [], ['checkout_uuid' => $checkout->uuid, 'amount_minor' => (int) $checkout->amount_minor]);

        $this->notifier->changed($ride, 'paid');
        if ($scheduled) {
            $this->notifier->rider($ride, 'scheduled', ['time' => ScheduledRideService::localTime($ride)]);

            return;
        }
        DB::afterCommit(fn () => app(DispatchEngine::class)->runRound($ride));
    }

    private function ride(Model $owner, string $uuid): Ride
    {
        $ride = $owner instanceof User ? Ride::query()->where('uuid', $uuid)->where('user_id', $owner->getKey())->first() : null;
        if ($ride === null) {
            throw new RidesException('ride_not_found', 404);
        }
        if ($ride->status !== RideStatus::AwaitingPayment) {
            throw new RidesException('ride_not_awaiting_payment', 409);
        }

        return $ride;
    }
}
