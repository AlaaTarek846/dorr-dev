<?php

namespace Modules\Rides\Services\Bus;

use App\Services\Notifications\NotificationCenter;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Driver\Enums\VehicleBodyType;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\Vehicle;
use Modules\Rides\Events\BusSeatsChanged;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\BusBooking;
use Modules\Rides\Models\BusBookingSeat;
use Modules\Rides\Models\BusTrip;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Rides\Support\Geo;
use Modules\User\Models\User;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\Wallet\Services\WalletService;

/**
 * Bus booking (docs/rides_tasks.md O-20..O-24). The captain publishes a trip on his bus — from, to, when
 * it leaves and comes back, a price for going, coming back, and going and back. Riders find it by where
 * they leave from, where they go, or both, and book seats like a plane ticket, paid from the wallet
 * (non-withdrawable money first). One seat per leg per ticket (a unique key: two riders can never get
 * the same seat). The captain sees who booked, boards them by their ticket, and is paid when the trip
 * is over (DORR's commission off, from the `bus` category's policy).
 */
class BusService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly NotificationCenter $notifications,
        private readonly RidePolicies $policies,
        private readonly FinancialLedgerService $ledger,
    ) {}

    // ---------------------------------------------------------------- the captain

    /** The captain's bus: an approved, active vehicle with a bus / minibus body. */
    public function busOf(Driver $driver): Vehicle
    {
        $bus = Vehicle::query()->where('driver_id', $driver->id)->where('status', VehicleStatus::Approved->value)
            ->whereIn('body_type', [VehicleBodyType::Bus->value, VehicleBodyType::Minibus->value])
            ->orderByDesc('is_active')->first();

        return $bus ?? throw new RidesException('bus_no_bus', 422);
    }

    /** @param array<string, mixed> $data */
    public function publish(Driver $driver, array $data): BusTrip
    {
        $bus = $this->busOf($driver);
        $this->assertTimes($data);
        $seats = (int) ($data['seats_total'] ?? $bus->seats);
        if ($seats < 1 || $seats > max(1, (int) $bus->seats)) {
            throw new RidesException('bus_seats_over_bus', 422, ['max' => (int) $bus->seats], ['max' => (int) $bus->seats]);
        }

        $trip = BusTrip::query()->create([
            'driver_id' => $driver->id,
            'vehicle_id' => $bus->id,
            'country_id' => $bus->country_id ?? $driver->country_id,
            'currency_id' => $bus->country?->currency_id ?? $driver->country?->currency_id,
            'origin_lat' => $data['origin']['lat'], 'origin_lng' => $data['origin']['lng'], 'origin_address' => $data['origin']['address'],
            'destination_lat' => $data['destination']['lat'], 'destination_lng' => $data['destination']['lng'], 'destination_address' => $data['destination']['address'],
            'depart_at' => $data['depart_at'],
            'return_at' => $data['return_at'] ?? null,
            'price_outbound_minor' => (int) $data['price_outbound_minor'],
            'price_return_minor' => isset($data['return_at']) ? (int) ($data['price_return_minor'] ?? $data['price_outbound_minor']) : null,
            'price_round_minor' => isset($data['return_at']) && isset($data['price_round_minor']) ? (int) $data['price_round_minor'] : null,
            'seats_total' => $seats,
            'seats_per_row' => (int) ($data['seats_per_row'] ?? 4),
            'note' => $data['note'] ?? null,
        ]);

        return $trip->refresh();
    }

    /** Prices and the note always; the times and the seats only while nobody booked. @param array<string, mixed> $data */
    public function update(Driver $driver, BusTrip $trip, array $data): BusTrip
    {
        $this->assertOwner($driver, $trip);
        if ($trip->status !== 'scheduled') {
            throw new RidesException('bus_trip_not_editable', 409);
        }
        $booked = $trip->bookings()->where('status', 'confirmed')->exists();
        $fill = array_intersect_key($data, array_flip(['price_outbound_minor', 'price_return_minor', 'price_round_minor', 'note']));
        if (! $booked) {
            $this->assertTimes($data + ['depart_at' => $trip->depart_at, 'return_at' => $trip->return_at]);
            $fill += array_intersect_key($data, array_flip(['depart_at', 'return_at', 'seats_total', 'seats_per_row']));
            if (isset($data['origin'])) {
                $fill += ['origin_lat' => $data['origin']['lat'], 'origin_lng' => $data['origin']['lng'], 'origin_address' => $data['origin']['address']];
            }
            if (isset($data['destination'])) {
                $fill += ['destination_lat' => $data['destination']['lat'], 'destination_lng' => $data['destination']['lng'], 'destination_address' => $data['destination']['address']];
            }
        } elseif (array_intersect_key($data, array_flip(['depart_at', 'return_at', 'seats_total', 'origin', 'destination'])) !== []) {
            throw new RidesException('bus_trip_has_bookings', 409);
        }
        $trip->fill($fill)->save();

        return $trip->refresh();
    }

    /** The captain calls the trip off: every ticket is refunded in full and its rider told. */
    public function cancelTrip(Driver $driver, BusTrip $trip): BusTrip
    {
        $this->assertOwner($driver, $trip);
        if (! in_array($trip->status, ['scheduled'], true)) {
            throw new RidesException('bus_trip_not_editable', 409);
        }
        DB::transaction(function () use ($trip) {
            $trip->forceFill(['status' => 'canceled', 'canceled_at' => now()])->save();
            foreach ($trip->bookings()->where('status', 'confirmed')->get() as $booking) {
                $this->refund($booking, 'trip_canceled');
                $this->notifications->send($booking->user, 'bus.trip_canceled', 'bus_trip_canceled_title', 'bus_trip_canceled_body', [
                    'route' => $this->routeText($trip), 'time' => $this->timeText($trip->depart_at),
                ], ['type' => 'bus', 'booking_uuid' => $booking->uuid, 'trip_uuid' => $trip->uuid]);
            }
        });
        event(new BusSeatsChanged($trip->uuid));

        return $trip->refresh();
    }

    /** The bus leaves (going), turns back (coming back), or the trip is over — then the captain is paid. */
    public function move(Driver $driver, BusTrip $trip, string $to): BusTrip
    {
        $this->assertOwner($driver, $trip);
        $allowed = ['departed' => ['scheduled'], 'returning' => ['departed'], 'completed' => ['departed', 'returning']][$to] ?? [];
        if (! in_array($trip->status, $allowed, true)) {
            throw new RidesException('bus_trip_not_editable', 409);
        }
        // O-68: it leaves at its time — not a day early (a little before, by the policy).
        $opensAt = $this->startsAt($trip, $to);
        if ($opensAt !== null && now()->lessThan($opensAt)) {
            throw new RidesException('bus_trip_too_early', 409, ['time' => $this->timeText($to === 'returning' ? $trip->return_at : $trip->depart_at)]);
        }
        // The passengers of that leg, before the tickets are closed.
        $riders = $trip->bookings()->where('status', 'confirmed')
            ->when($to === 'departed', fn ($q) => $q->whereIn('trip_type', ['outbound', 'round']))
            ->when($to === 'returning', fn ($q) => $q->whereIn('trip_type', ['return', 'round']))
            ->with('user')->get();
        $earned = 0;
        DB::transaction(function () use ($trip, $to, &$earned) {
            $trip->forceFill(['status' => $to] + match ($to) {
                'departed' => ['departed_at' => now()],
                'completed' => ['completed_at' => now()],
                default => [],
            })->save();
            if ($to === 'completed') {
                $earned = $this->payDriver($trip);
            }
        });
        $trip->refresh();

        // R-146: DORR points for each ticket of a trip that's over.
        if ($to === 'completed') {
            foreach ($riders as $b) {
                app(\Modules\Rides\Services\Loyalty\LoyaltyService::class)->earnForService($b->user, (int) $trip->country_id,
                    max(0, $b->amount_minor - (int) $b->discount_minor), (int) $trip->currency_id, 'bus_booking', (int) $b->id, $this->routeText($trip));
            }
        }
        // Everyone on it hears the trip move: it left, it's coming back, it's over.
        foreach ($riders as $b) {
            $this->notifications->send($b->user, 'bus.'.$to, 'bus_'.$to.'_title', 'bus_'.$to.'_body', [
                'route' => $this->routeText($trip), 'name' => strtok((string) $trip->driver?->name, ' ') ?: '',
            ], ['type' => 'bus', 'booking_uuid' => $b->uuid, 'trip_uuid' => $trip->uuid]);
        }
        if ($to === 'completed') {
            app(\Modules\Rides\Services\Notifications\ServiceNotices::class)->earning($trip->driver, 'bus', $earned, $trip->currency_id, $this->routeText($trip), ['trip_uuid' => $trip->uuid]);
        }

        return $trip;
    }

    /** O-68: from when the captain may start this leg (null: any time). */
    public function startsAt(BusTrip $trip, string $to): ?CarbonInterface
    {
        $at = match ($to) { 'departed' => $trip->depart_at, 'returning' => $trip->return_at, default => null };
        $early = $this->policy($trip->country_id)->int('bus.depart_early_s') ?: 1800;

        return $at?->copy()->subSeconds($early);
    }

    /** Boarding: the ticket's QR (or the seat on the list) — once. */
    public function board(Driver $driver, BusTrip $trip, ?string $qr, ?string $leg, ?int $seat): BusBookingSeat
    {
        $this->assertOwner($driver, $trip);
        $row = BusBookingSeat::query()->where('bus_trip_id', $trip->id)
            ->when($qr !== null, fn ($q) => $q->where('qr_token', $qr), fn ($q) => $q->where('leg', $leg)->where('seat_no', $seat))
            ->first() ?? throw new RidesException('bus_ticket_not_found', 404);
        if ($row->booking?->status !== 'confirmed') {
            throw new RidesException('bus_ticket_not_found', 404);
        }
        if ($row->boarded_at === null) {
            $row->forceFill(['boarded_at' => now()])->save();
            // The passenger's ticket turns «on board» at once (no push — they're at the door).
            $this->notifications->send($row->booking->user, 'bus.boarded', 'bus_boarded_title', 'bus_boarded_body', ['seat' => $row->seat_no], [
                'type' => 'bus', 'booking_uuid' => $row->booking->uuid, 'trip_uuid' => $trip->uuid,
            ], false);
        }

        return $row;
    }

    /** @return Collection<int, BusTrip> */
    public function mine(Driver $driver): Collection
    {
        return BusTrip::query()->where('driver_id', $driver->id)
            ->orderByRaw("CASE WHEN status IN ('completed','canceled') THEN 1 ELSE 0 END")
            ->orderBy('depart_at')->limit(100)->get();
    }

    // ---------------------------------------------------------------- the rider

    /**
     * Trips from where the rider leaves, to where they go, or both (O-22) — going legs, and the coming-back
     * leg of trips that return (from their destination back to their origin). Nearest departure first.
     *
     * @param  array{lat: float, lng: float}|null  $from
     * @param  array{lat: float, lng: float}|null  $to
     * @return list<array{trip: BusTrip, leg: string, from_m: ?int, to_m: ?int}>
     */
    public function search(?array $from, ?array $to, ?CarbonInterface $day, int $countryId): array
    {
        $this->releaseLapsed();
        $radius = $this->policy($countryId)->int('bus.search_radius_m') ?: 15000;
        $trips = BusTrip::query()->where('country_id', $countryId)->where('status', 'scheduled')
            ->where(fn ($q) => $q->where('depart_at', '>', now())->orWhere('return_at', '>', now()))
            ->when($day, fn ($q) => $q->where(fn ($w) => $w->whereDate('depart_at', $day->toDateString())->orWhereDate('return_at', $day->toDateString())))
            ->orderBy('depart_at')->limit(300)->get();

        $near = fn (?array $p, float $lat, float $lng) => $p === null ? null : (int) round(Geo::metres($p['lat'], $p['lng'], $lat, $lng));
        $found = [];
        foreach ($trips as $trip) {
            // Going: leaves near "from", arrives near "to".
            $a = $near($from, $trip->origin_lat, $trip->origin_lng);
            $b = $near($to, $trip->destination_lat, $trip->destination_lng);
            if ($trip->depart_at->isFuture() && ($a === null || $a <= $radius) && ($b === null || $b <= $radius)) {
                $found[] = ['trip' => $trip, 'leg' => 'outbound', 'from_m' => $a, 'to_m' => $b];
            }
            // Coming back: from the trip's destination to its origin.
            if ($trip->hasReturn() && $trip->return_at->isFuture()) {
                $c = $near($from, $trip->destination_lat, $trip->destination_lng);
                $d = $near($to, $trip->origin_lat, $trip->origin_lng);
                if (($c === null || $c <= $radius) && ($d === null || $d <= $radius) && ($from !== null || $to !== null)) {
                    $found[] = ['trip' => $trip, 'leg' => 'return', 'from_m' => $c, 'to_m' => $d];
                }
            }
        }
        usort($found, fn ($x, $y) => ($x['leg'] === 'return' ? $x['trip']->return_at : $x['trip']->depart_at) <=> ($y['leg'] === 'return' ? $y['trip']->return_at : $y['trip']->depart_at));

        return $found;
    }

    /** O-67: how long seats are held for the payment screen. */
    public const HOLD_MINUTES = 15;

    /**
     * Books seats (O-23): going, coming back, or both — one ticket. O-67: the seats are held «unpaid» for
     * HOLD_MINUTES and the app's one payment screen pays it (coupon, top-up) — confirmPaid() confirms it.
     *
     * @param  list<int>  $outbound
     * @param  list<int>  $return
     */
    public function book(User $rider, BusTrip $trip, string $tripType, array $outbound, array $return, ?string $key): BusBooking
    {
        if ($key !== null && ($done = BusBooking::query()->where('user_id', $rider->id)->where('idempotency_key', $key)->first()) !== null) {
            return $done;
        }
        if ($trip->status !== 'scheduled') {
            throw new RidesException('bus_trip_closed', 409);
        }
        $this->releaseLapsed($trip);
        $price = $trip->seatPrice($tripType) ?? throw new RidesException('bus_no_return', 422);
        $outbound = $tripType === 'return' ? [] : array_values(array_unique(array_map('intval', $outbound)));
        $return = $tripType === 'outbound' ? [] : array_values(array_unique(array_map('intval', $return)));
        if ($tripType !== 'return' && ! $trip->depart_at->isFuture() || $tripType !== 'outbound' && ! $trip->return_at?->isFuture()) {
            throw new RidesException('bus_trip_closed', 409);
        }
        // Going and back: the same number of seats on each leg; each leg needs at least one.
        $count = $tripType === 'return' ? count($return) : count($outbound);
        if ($count === 0 || ($tripType === 'round' && count($return) !== $count)) {
            throw new RidesException('bus_pick_seats', 422);
        }
        foreach ([$outbound, $return] as $list) {
            foreach ($list as $n) {
                if ($n < 1 || $n > $trip->seats_total) {
                    throw new RidesException('bus_seat_unknown', 422, ['seat' => $n]);
                }
            }
        }
        $max = $this->policy($trip->country_id)->int('bus.max_seats_per_booking') ?: 10;
        if ($count > $max) {
            throw new RidesException('bus_too_many_seats', 422, ['max' => $max], ['max' => $max]);
        }
        $amount = $price * $count;

        try {
            $booking = DB::transaction(function () use ($rider, $trip, $tripType, $outbound, $return, $amount, $key) {
                $booking = BusBooking::query()->create([
                    'bus_trip_id' => $trip->id,
                    'user_id' => $rider->id,
                    'trip_type' => $tripType,
                    'seats' => ['outbound' => $outbound, 'return' => $return],
                    'amount_minor' => $amount,
                    'currency_id' => $trip->currency_id,
                    'idempotency_key' => $key,
                    'status' => 'unpaid',
                    'payment_expires_at' => now()->addMinutes(self::HOLD_MINUTES),
                ]);
                // The unique (trip, leg, seat) key makes a seat somebody just took fail the whole booking.
                foreach (['outbound' => $outbound, 'return' => $return] as $leg => $list) {
                    foreach ($list as $n) {
                        BusBookingSeat::query()->create([
                            'bus_booking_id' => $booking->id, 'bus_trip_id' => $trip->id, 'leg' => $leg, 'seat_no' => $n,
                            'qr_token' => 'DB-'.Str::upper(Str::random(20)),
                        ]);
                    }
                }

                return $booking;
            });
        } catch (QueryException) {
            throw new RidesException('bus_seat_taken', 409);
        }

        // The seats show as taken at once (held for the payment).
        event(new BusSeatsChanged($trip->uuid));

        return $booking->refresh();
    }

    /**
     * O-67: the payment screen paid it — `$payable` (after the coupon) is taken from the wallet now, the
     * ticket is confirmed, the captain hears of it and the rider gets the confirmation.
     */
    public function confirmPaid(BusBooking $booking, int $payable, int $discount): BusBooking
    {
        if ($booking->status === 'confirmed') {
            return $booking;
        }
        if ($booking->status !== 'unpaid' || ($booking->payment_expires_at !== null && $booking->payment_expires_at->isPast())) {
            throw new RidesException('booking_lapsed', 409);
        }
        $trip = $booking->trip;
        $rider = $booking->user()->firstOrFail();
        $booking->forceFill([
            'status' => 'confirmed', 'payment_expires_at' => null, 'discount_minor' => $discount,
            'wallet_transaction_ids' => $payable > 0 ? $this->charge($rider, $trip, $booking, $payable) : [],
        ])->save();
        $count = max(count((array) ($booking->seats['outbound'] ?? [])), count((array) ($booking->seats['return'] ?? [])));
        // O-24: the captain hears of every booking; the rider gets the confirmation.
        $this->notifications->send($trip->driver, 'bus.booked', 'bus_booked_title', 'bus_booked_body', [
            'name' => strtok((string) $rider->name, ' ') ?: '', 'seats' => $count, 'route' => $this->routeText($trip),
        ], ['type' => 'bus', 'trip_uuid' => $trip->uuid, 'booking_uuid' => $booking->uuid]);
        $this->notifications->send($rider, 'bus.booking_confirmed', 'bus_booking_confirmed_title', 'bus_booking_confirmed_body', [
            'route' => $this->routeText($trip), 'time' => $this->timeText($booking->trip_type === 'return' ? $trip->return_at : $trip->depart_at),
        ], ['type' => 'bus', 'booking_uuid' => $booking->uuid]);

        return $booking->refresh();
    }

    /** O-67: seats held for a payment that never came are free again. */
    public function releaseLapsed(?BusTrip $trip = null): void
    {
        BusBooking::query()->where('status', 'unpaid')->where('payment_expires_at', '<', now())
            ->when($trip, fn ($q) => $q->where('bus_trip_id', $trip->id))->limit(100)->get()
            ->each(function (BusBooking $b) {
                $b->seatRows()->delete();
                $b->forceFill(['status' => 'lapsed'])->save();
                event(new BusSeatsChanged((string) $b->trip()->value('uuid')));
                app(\Modules\Rides\Services\Notifications\ServiceNotices::class)->lapsed($b->user, 'bus', $b->uuid, __('rides.checkout.bus', ['seats' => max(count((array) ($b->seats['outbound'] ?? [])), count((array) ($b->seats['return'] ?? [])))]));
            });
    }

    /** The rider cancels: refunded in full up to `bus.free_cancel_before_s` before leaving; after that it can't be. */
    public function cancelBooking(User $rider, BusBooking $booking): BusBooking
    {
        if ($booking->user_id !== $rider->id) {
            throw new RidesException('bus_ticket_not_found', 404);
        }
        // O-67: not paid yet — the held seats are simply let go.
        if ($booking->status === 'unpaid') {
            $booking->seatRows()->delete();
            $booking->forceFill(['status' => 'canceled', 'canceled_at' => now(), 'cancel_reason' => 'unpaid'])->save();
            event(new BusSeatsChanged($booking->trip->uuid));

            return $booking->refresh();
        }
        if ($booking->status !== 'confirmed') {
            return $booking;
        }
        $trip = $booking->trip;
        $leaves = $booking->trip_type === 'return' ? $trip->return_at : $trip->depart_at;
        $window = $this->policy($trip->country_id)->int('bus.free_cancel_before_s') ?: 3600;
        if ($trip->status === 'scheduled' && $leaves !== null && now()->addSeconds($window)->greaterThan($leaves)) {
            throw new RidesException('bus_too_late_to_cancel', 409, ['minutes' => intdiv($window, 60)]);
        }
        DB::transaction(fn () => $this->refund($booking, 'rider_canceled'));
        event(new BusSeatsChanged($trip->uuid));
        $this->notifications->send($trip->driver, 'bus.booking_canceled', 'bus_booking_canceled_title', 'bus_booking_canceled_body', [
            'name' => strtok((string) $rider->name, ' ') ?: '', 'route' => $this->routeText($trip),
        ], ['type' => 'bus', 'trip_uuid' => $trip->uuid]);

        return $booking->refresh();
    }

    // ---------------------------------------------------------------- money

    /** @return list<int> the wallet transactions taken (non-withdrawable first — O-38f) */
    private function charge(User $rider, BusTrip $trip, BusBooking $booking, int $amount): array
    {
        $wallet = $this->wallets->firstOrCreateWallet($rider, $trip->country()->firstOrFail())->fresh();
        $free = max(0, $wallet->availableMinor(WalletBucket::SpendOnly)) + max(0, $wallet->availableMinor(WalletBucket::Withdrawable));
        if ($free < $amount) {
            throw new RidesException('insufficient_balance', 422, [], ['shortfall_minor' => $amount - $free]);
        }
        $key = 'bus:'.$booking->uuid.':pay';
        $this->wallets->debitSpendOnlyFirst($wallet, $amount, WalletTransactionType::ServicePayment, [
            'reference_type' => 'bus_booking', 'reference_id' => $booking->id, 'idempotency_key' => $key,
            'notes' => ['key' => 'wallet.notes.bus_ticket', 'variables' => ['route' => $this->routeText($trip)]],
        ]);

        return WalletTransaction::query()->whereIn('idempotency_key', [$key, $key.':spend_only', $key.':withdrawable'])->pluck('id')->all();
    }

    /** Every wallet line the ticket took goes back the way it came, and its seats are free again. */
    private function refund(BusBooking $booking, string $reason): void
    {
        foreach (WalletTransaction::query()->whereIn('id', (array) $booking->wallet_transaction_ids)->get() as $tx) {
            if (! $tx->isReversed()) {
                $this->wallets->reverse($tx, 'bus_'.$reason, ['idempotency_key' => 'bus:'.$booking->uuid.':refund:'.$tx->id]);
            }
        }
        $booking->seatRows()->delete();
        $booking->forceFill(['status' => 'canceled', 'canceled_at' => now(), 'cancel_reason' => $reason])->save();
    }

    /** The trip is over: the tickets, less DORR's commission (the `bus` category's), go to the captain. */
    /** @return int the captain's share credited */
    private function payDriver(BusTrip $trip): int
    {
        $bookings = $trip->bookings()->where('status', 'confirmed')->get();
        // O-67: what the riders paid (after their coupons) is what's shared.
        $total = (int) $bookings->sum(fn (BusBooking $b) => max(0, $b->amount_minor - (int) $b->discount_minor));
        $bookings->each->update(['status' => 'completed']);
        if ($total <= 0) {
            return 0;
        }
        $policy = $this->policy($trip->country_id);
        $commission = ($policy->get('price.commission_type') ?? 'percent') === 'fixed'
            ? min($total, (int) $policy->int('price.commission_value') * $bookings->count())
            : (int) round($total * $policy->int('price.commission_value') / 10000);
        $country = $trip->country()->firstOrFail();
        $wallet = $this->wallets->firstOrCreateWallet($trip->driver()->firstOrFail(), $country);
        $this->wallets->credit($wallet, $total - $commission, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, [
            'reference_type' => 'bus_trip', 'reference_id' => $trip->id, 'idempotency_key' => 'bus:'.$trip->uuid.':earning',
            'notes' => ['key' => 'wallet.notes.bus_earning', 'variables' => ['route' => $this->routeText($trip)]],
        ]);
        if ($commission > 0 && $trip->currency !== null) {
            $this->ledger->record('trip_commissions', FinancialEntryType::Income, $commission, $trip->currency, $country, $trip,
                ['key' => 'wallet.notes.bus_commission', 'variables' => ['route' => $this->routeText($trip)]]);
        }

        return $total - $commission;
    }

    // ---------------------------------------------------------------- helpers

    public function policy(int $countryId): \Modules\Rides\Services\Policy\RidePolicyData
    {
        $category = RideCategory::query()->where('slug', 'bus')->value('id');

        return $this->policies->for($countryId, null, null, $category !== null ? (int) $category : null);
    }

    /** @param array<string, mixed> $data */
    private function assertTimes(array $data): void
    {
        $depart = \Illuminate\Support\Carbon::parse($data['depart_at']);
        if (! $depart->isFuture()) {
            throw new RidesException('bus_time_past', 422);
        }
        if (! empty($data['return_at']) && \Illuminate\Support\Carbon::parse($data['return_at'])->lessThanOrEqualTo($depart)) {
            throw new RidesException('bus_return_before_departure', 422);
        }
    }

    private function assertOwner(Driver $driver, BusTrip $trip): void
    {
        if ($trip->driver_id !== $driver->id) {
            throw new RidesException('bus_trip_not_found', 404);
        }
    }

    public function routeText(BusTrip $trip): string
    {
        return Str::limit($trip->origin_address, 40).' ← '.Str::limit($trip->destination_address, 40);
    }

    private function timeText(?CarbonInterface $at): string
    {
        return $at?->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i') ?? '';
    }
}
