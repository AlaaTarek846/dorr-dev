<?php

namespace Modules\Rides\Services\Marine;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Enums\VehicleBodyType;
use Modules\Driver\Enums\VehicleStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\Vehicle;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\MarineBooking;
use Modules\Rides\Models\MarineListing;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Rides\Services\Policy\RidePolicyData;
use Modules\Rides\Support\Geo;
use Modules\User\Models\User;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\Wallet\Services\WalletService;

/**
 * Boats & yachts (docs/rides_tasks.md O-25; R-29, R-41, R-42, D-80, D-100..D-102).
 *
 *  - Sea trip: the captain publishes the marina, the route, when it leaves and for how long; a guest buys
 *    seats (shared) or the whole boat (private — only while nobody shares it, and then nobody else can).
 *  - Yacht hire: by the hour (at least `min_hours`), from the marina, at the time the guest picks; two hires
 *    of the same boat never overlap. During the hire the guest may ask for more time: the captain agrees
 *    (or not), and the extra hours are charged then (R-42, D-102).
 *
 * Paid from the wallet (non-withdrawable money first), refunded in full up to `marine.free_cancel_before_s`
 * before it starts, boarded by the booking's QR, and the captain is paid when it's over (less the
 * `marine` category's commission).
 */
class MarineService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly NotificationCenter $notifications,
        private readonly RidePolicies $policies,
        private readonly FinancialLedgerService $ledger,
    ) {}

    // ---------------------------------------------------------------- the captain

    public function boatOf(Driver $driver): Vehicle
    {
        return Vehicle::query()->where('driver_id', $driver->id)->where('status', VehicleStatus::Approved->value)
            ->whereIn('body_type', [VehicleBodyType::Boat->value, VehicleBodyType::Yacht->value])
            ->orderByDesc('is_active')->first() ?? throw new RidesException('marine_no_boat', 422);
    }

    /** @param array<string, mixed> $data */
    public function publish(Driver $driver, array $data): MarineListing
    {
        $boat = $this->boatOf($driver);
        $capacity = (int) ($data['capacity'] ?? $boat->seats);
        if ($capacity < 1 || $capacity > max(1, (int) $boat->seats)) {
            throw new RidesException('marine_capacity_over_boat', 422, ['max' => (int) $boat->seats], ['max' => (int) $boat->seats]);
        }
        if ($data['kind'] === 'trip' && ! Carbon::parse($data['depart_at'])->isFuture()) {
            throw new RidesException('bus_time_past', 422);
        }

        return MarineListing::query()->create([
            'driver_id' => $driver->id, 'vehicle_id' => $boat->id,
            'country_id' => $boat->country_id ?? $driver->country_id,
            'currency_id' => $boat->country?->currency_id ?? $driver->country?->currency_id,
            'kind' => $data['kind'], 'title' => $data['title'], 'description' => $data['description'] ?? null,
            'marina_lat' => $data['marina']['lat'], 'marina_lng' => $data['marina']['lng'], 'marina_address' => $data['marina']['address'],
            'route_note' => $data['route_note'] ?? null,
            'amenities' => array_values(array_intersect((array) ($data['amenities'] ?? []), MarineListing::AMENITIES)),
            'capacity' => $capacity,
            'depart_at' => $data['kind'] === 'trip' ? $data['depart_at'] : null,
            'duration_min' => $data['kind'] === 'trip' ? (int) $data['duration_min'] : null,
            'seat_price_minor' => $data['kind'] === 'trip' ? ($data['seat_price_minor'] ?? null) : null,
            'private_price_minor' => $data['kind'] === 'trip' ? ($data['private_price_minor'] ?? null) : null,
            'hour_price_minor' => $data['kind'] === 'rental' ? (int) $data['hour_price_minor'] : null,
            'min_hours' => $data['kind'] === 'rental' ? max(1, (int) ($data['min_hours'] ?? 1)) : null,
        ])->refresh();
    }

    /** active ↔ paused (a hire stops taking bookings), departed → completed (then paid), or cancelled (all refunded). */
    public function move(Driver $driver, MarineListing $listing, string $to): MarineListing
    {
        $this->assertOwner($driver, $listing);
        $allowed = ['paused' => ['active'], 'active' => ['paused'], 'departed' => ['active'], 'completed' => ['departed', 'active'], 'canceled' => ['active', 'paused']][$to] ?? [];
        if (! in_array($listing->status, $allowed, true)) {
            throw new RidesException('marine_not_editable', 409);
        }
        DB::transaction(function () use ($listing, $to) {
            $listing->forceFill(['status' => $to])->save();
            if ($to === 'completed') {
                foreach ($listing->bookings()->whereIn('status', ['confirmed', 'boarded'])->get() as $b) {
                    $b->forceFill(['status' => 'completed'])->save();
                    $this->payCaptain($b);
                }
            }
            if ($to === 'canceled') {
                foreach ($listing->bookings()->whereIn('status', ['confirmed'])->get() as $b) {
                    $this->refund($b);
                    $this->notifications->send($b->user, 'marine.canceled', 'marine_canceled_title', 'marine_canceled_body', ['title' => $listing->title], ['type' => 'marine', 'booking_uuid' => $b->uuid]);
                }
            }
        });

        return $listing->refresh();
    }

    /** A hire's (or a trip's) booking is over — or the whole trip is: the captain is paid for it. */
    public function finish(Driver $driver, MarineBooking $booking): MarineBooking
    {
        $this->assertOwner($driver, $booking->listing);
        if (! in_array($booking->status, ['confirmed', 'boarded'], true)) {
            throw new RidesException('marine_not_editable', 409);
        }
        $earned = 0;
        DB::transaction(function () use ($booking, &$earned) {
            $booking->forceFill(['status' => 'completed'])->save();
            $earned = $this->payCaptain($booking);
        });
        $listing = $booking->listing;
        $this->notifications->send($booking->user, 'marine.completed', 'marine_completed_title', 'marine_completed_body', ['title' => $listing->title], [
            'type' => 'marine', 'booking_uuid' => $booking->uuid,
        ]);
        app(\Modules\Rides\Services\Notifications\ServiceNotices::class)->earning($listing->driver, 'marine', $earned, $listing->currency_id, $listing->title, ['listing_uuid' => $listing->uuid]);
        // R-146: the guest's DORR points.
        app(\Modules\Rides\Services\Loyalty\LoyaltyService::class)->earnForService($booking->user, (int) $listing->country_id,
            max(0, $booking->amount_minor - (int) $booking->discount_minor) + (int) $booking->extension_minor, (int) $listing->currency_id, 'marine_booking', (int) $booking->id, $listing->title);

        return $booking->refresh();
    }

    public function board(Driver $driver, string $qr): MarineBooking
    {
        $booking = MarineBooking::query()->where('qr_token', $qr)->with('listing')->first() ?? throw new RidesException('bus_ticket_not_found', 404);
        $this->assertOwner($driver, $booking->listing);
        if ($booking->status === 'confirmed') {
            $booking->forceFill(['status' => 'boarded'])->save();
            // The guest's booking turns «on board» at once (no push — they're at the gangway).
            $this->notifications->send($booking->user, 'marine.boarded', 'marine_boarded_title', 'marine_boarded_body', ['title' => $booking->listing->title], [
                'type' => 'marine', 'booking_uuid' => $booking->uuid,
            ], false);
        } elseif ($booking->status !== 'boarded') {
            throw new RidesException('bus_ticket_not_found', 404);
        }

        return $booking->refresh();
    }

    /** R-42, D-102: the captain answers a guest's request for more time. */
    public function answerExtension(Driver $driver, MarineBooking $booking, bool $approve): MarineBooking
    {
        $listing = $booking->listing;
        $this->assertOwner($driver, $listing);
        $request = (array) $booking->extension;
        if (($request['status'] ?? null) !== 'pending') {
            throw new RidesException('marine_no_extension', 409);
        }
        if (! $approve) {
            $booking->forceFill(['extension' => ['status' => 'declined'] + $request])->save();
            $this->notifications->send($booking->user, 'marine.extension_declined', 'marine_extension_declined_title', 'marine_extension_declined_body', ['title' => $listing->title], ['type' => 'marine', 'booking_uuid' => $booking->uuid]);

            return $booking->refresh();
        }

        DB::transaction(function () use ($booking, $listing, $request) {
            $until = $booking->ends_at->copy()->addHours((int) $request['hours']);
            $this->assertFree($listing, $booking->ends_at, $until, $booking->id);
            $ids = $this->charge($booking->user, $listing, (int) $request['amount_minor'], 'marine:'.$booking->uuid.':ext:'.$booking->ends_at->timestamp);
            $booking->forceFill([
                'ends_at' => $until,
                'extension_minor' => $booking->extension_minor + (int) $request['amount_minor'],
                'extension' => ['status' => 'approved'] + $request,
                'wallet_transaction_ids' => array_merge((array) $booking->wallet_transaction_ids, $ids),
            ])->save();
        });
        $this->notifications->send($booking->user, 'marine.extension_approved', 'marine_extension_approved_title', 'marine_extension_approved_body', [
            'hours' => (int) $request['hours'], 'title' => $listing->title,
        ], ['type' => 'marine', 'booking_uuid' => $booking->uuid]);

        return $booking->refresh();
    }

    /** @return Collection<int, MarineListing> */
    public function mine(Driver $driver): Collection
    {
        return MarineListing::query()->where('driver_id', $driver->id)->latest('id')->limit(100)->get();
    }

    // ---------------------------------------------------------------- the guest

    /**
     * Sea trips coming up and yachts for hire near a place (or everywhere in the country).
     *
     * @param  array{lat: float, lng: float}|null  $near
     * @return Collection<int, MarineListing>
     */
    public function browse(int $countryId, ?string $kind, ?array $near): Collection
    {
        $radius = $this->policy($countryId)->int('marine.search_radius_m') ?: 60000;

        return MarineListing::query()->where('country_id', $countryId)->where('status', 'active')
            ->when($kind, fn ($q, $k) => $q->where('kind', $k))
            ->where(fn ($q) => $q->where('kind', 'rental')->orWhere('depart_at', '>', now()))
            ->with(['vehicle.make.translations', 'vehicle.model.translations', 'driver'])
            ->limit(200)->get()
            ->filter(fn (MarineListing $l) => $near === null || Geo::metres($near['lat'], $near['lng'], $l->marina_lat, $l->marina_lng) <= $radius)
            ->sortBy(fn (MarineListing $l) => $l->kind === 'trip' ? $l->depart_at->timestamp : PHP_INT_MAX)
            ->values();
    }

    /** Books a sea trip (shared seats or the whole boat) or a yacht hire (a start time and hours). */
    public function book(User $guest, MarineListing $listing, string $mode, int $guests, ?Carbon $startsAt, ?int $hours, ?string $key): MarineBooking
    {
        if ($key !== null && ($done = MarineBooking::query()->where('user_id', $guest->id)->where('idempotency_key', $key)->first()) !== null) {
            return $done;
        }
        if ($listing->status !== 'active') {
            throw new RidesException('marine_closed', 409);
        }
        if ($guests < 1 || $guests > $listing->capacity) {
            throw new RidesException('marine_too_many_guests', 422, ['max' => $listing->capacity], ['max' => $listing->capacity]);
        }

        $booking = DB::transaction(function () use ($guest, $listing, $mode, $guests, $startsAt, $hours, $key) {
            $listing = MarineListing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();
            if ($listing->kind === 'trip') {
                if (! $listing->depart_at->isFuture()) {
                    throw new RidesException('marine_closed', 409);
                }
                $private = $mode === 'private';
                $price = $private ? $listing->private_price_minor : ($listing->seat_price_minor === null ? null : $listing->seat_price_minor * $guests);
                if ($price === null) {
                    throw new RidesException('marine_mode_unavailable', 422);
                }
                $left = $listing->seatsLeft();
                if ($private ? $left < $listing->capacity : $left < $guests) {
                    throw new RidesException('marine_full', 409, ['left' => $left], ['seats_left' => $left]);
                }
                $from = $listing->depart_at;
                $to = $listing->depart_at->copy()->addMinutes((int) $listing->duration_min);
                $mode = $private ? 'private' : 'shared';
            } else {
                $hours = max((int) $listing->min_hours, (int) $hours);
                if ($startsAt === null || ! $startsAt->isFuture()) {
                    throw new RidesException('bus_time_past', 422);
                }
                $from = $startsAt;
                $to = $startsAt->copy()->addHours($hours);
                $this->assertFree($listing, $from, $to);
                $price = $listing->hour_price_minor * $hours;
                $mode = 'rental';
            }

            // O-67: held «unpaid» while the app's payment screen pays it (coupon, top-up).
            return MarineBooking::query()->create([
                'marine_listing_id' => $listing->id, 'user_id' => $guest->id, 'mode' => $mode, 'guests' => $guests,
                'starts_at' => $from, 'ends_at' => $to, 'amount_minor' => $price, 'currency_id' => $listing->currency_id, 'idempotency_key' => $key,
                'status' => 'unpaid', 'payment_expires_at' => now()->addMinutes(\Modules\Rides\Services\Bus\BusService::HOLD_MINUTES),
            ]);
        });

        return $booking->refresh();
    }

    /** O-67: paid on the payment screen — the rest (after the coupon) is taken now and the booking confirmed. */
    public function confirmPaid(MarineBooking $booking, int $payable, int $discount): MarineBooking
    {
        if ($booking->status === 'confirmed') {
            return $booking;
        }
        if ($booking->status !== 'unpaid' || ($booking->payment_expires_at !== null && $booking->payment_expires_at->isPast())) {
            throw new RidesException('booking_lapsed', 409);
        }
        $listing = $booking->listing;
        $guest = $booking->user()->firstOrFail();
        $booking->forceFill([
            'status' => 'confirmed', 'payment_expires_at' => null, 'discount_minor' => $discount,
            'wallet_transaction_ids' => $payable > 0 ? $this->charge($guest, $listing, $payable, 'marine:'.$booking->uuid.':pay') : [],
        ])->save();
        $this->notifications->send($listing->driver, 'marine.booked', 'marine_booked_title', 'marine_booked_body', [
            'name' => strtok((string) $guest->name, ' ') ?: '', 'title' => $listing->title, 'guests' => $booking->guests,
        ], ['type' => 'marine', 'listing_uuid' => $listing->uuid, 'booking_uuid' => $booking->uuid]);
        $this->notifications->send($guest, 'marine.confirmed', 'marine_confirmed_title', 'marine_confirmed_body', [
            'title' => $listing->title, 'time' => $booking->starts_at->copy()->setTimezone((string) config('rides.default_timezone', 'Asia/Riyadh'))->format('Y-m-d H:i'),
        ], ['type' => 'marine', 'booking_uuid' => $booking->uuid]);

        return $booking->refresh();
    }

    public function cancel(User $guest, MarineBooking $booking): MarineBooking
    {
        // O-67: not paid yet — let go, nothing to refund.
        if ($booking->user_id === $guest->id && $booking->status === 'unpaid') {
            $booking->forceFill(['status' => 'canceled', 'canceled_at' => now()])->save();

            return $booking->refresh();
        }
        if ($booking->user_id !== $guest->id || $booking->status !== 'confirmed') {
            throw new RidesException('marine_not_editable', 409);
        }
        $window = $this->policy($booking->listing->country_id)->int('marine.free_cancel_before_s') ?: 86400;
        if (now()->addSeconds($window)->greaterThan($booking->starts_at)) {
            throw new RidesException('marine_too_late', 409, ['hours' => intdiv($window, 3600)]);
        }
        DB::transaction(fn () => $this->refund($booking));
        $this->notifications->send($booking->listing->driver, 'marine.booking_canceled', 'marine_booking_canceled_title', 'marine_booking_canceled_body', [
            'name' => strtok((string) $guest->name, ' ') ?: '', 'title' => $booking->listing->title,
        ], ['type' => 'marine', 'listing_uuid' => $booking->listing->uuid]);

        return $booking->refresh();
    }

    /** R-42: more time on a hire — priced at the hour price, the captain agrees or not. */
    public function askExtension(User $guest, MarineBooking $booking, int $hours): MarineBooking
    {
        $listing = $booking->listing;
        if ($booking->user_id !== $guest->id || $booking->mode !== 'rental' || ! in_array($booking->status, ['confirmed', 'boarded'], true)) {
            throw new RidesException('marine_not_editable', 409);
        }
        if ((($booking->extension['status'] ?? null) === 'pending')) {
            throw new RidesException('marine_extension_pending', 409);
        }
        $amount = $listing->hour_price_minor * max(1, $hours);
        $booking->forceFill(['extension' => ['hours' => max(1, $hours), 'amount_minor' => $amount, 'status' => 'pending', 'asked_at' => now()->toIso8601String()]])->save();
        $this->notifications->send($listing->driver, 'marine.extension_asked', 'marine_extension_asked_title', 'marine_extension_asked_body', [
            'name' => strtok((string) $guest->name, ' ') ?: '', 'hours' => max(1, $hours),
        ], ['type' => 'marine', 'listing_uuid' => $listing->uuid, 'booking_uuid' => $booking->uuid]);

        return $booking->refresh();
    }

    // ---------------------------------------------------------------- money and rules

    /** @return list<int> */
    private function charge(User $guest, MarineListing $listing, int $amount, string $key): array
    {
        $wallet = $this->wallets->firstOrCreateWallet($guest, $listing->country()->firstOrFail())->fresh();
        $free = max(0, $wallet->availableMinor(WalletBucket::SpendOnly)) + max(0, $wallet->availableMinor(WalletBucket::Withdrawable));
        if ($free < $amount) {
            throw new RidesException('insufficient_balance', 422, [], ['shortfall_minor' => $amount - $free]);
        }
        $this->wallets->debitSpendOnlyFirst($wallet, $amount, WalletTransactionType::ServicePayment, [
            'reference_type' => 'marine_listing', 'reference_id' => $listing->id, 'idempotency_key' => $key,
            'notes' => ['key' => 'wallet.notes.marine_booking', 'variables' => ['title' => $listing->title]],
        ]);

        return WalletTransaction::query()->whereIn('idempotency_key', [$key, $key.':spend_only', $key.':withdrawable'])->pluck('id')->all();
    }

    private function refund(MarineBooking $booking): void
    {
        foreach (WalletTransaction::query()->whereIn('id', (array) $booking->wallet_transaction_ids)->get() as $tx) {
            if (! $tx->isReversed()) {
                $this->wallets->reverse($tx, 'marine_canceled', ['idempotency_key' => 'marine:'.$booking->uuid.':refund:'.$tx->id]);
            }
        }
        $booking->forceFill(['status' => 'canceled', 'canceled_at' => now()])->save();
    }

    /** @return int the captain's share credited */
    private function payCaptain(MarineBooking $booking): int
    {
        $listing = $booking->listing;
        // O-67: what the guest paid (after a coupon), plus any extra time.
        $total = max(0, $booking->amount_minor - (int) $booking->discount_minor) + $booking->extension_minor;
        $policy = $this->policy($listing->country_id);
        $commission = ($policy->get('price.commission_type') ?? 'percent') === 'fixed'
            ? min($total, $policy->int('price.commission_value'))
            : (int) round($total * $policy->int('price.commission_value') / 10000);
        $country = $listing->country()->firstOrFail();
        $this->wallets->credit($this->wallets->firstOrCreateWallet($listing->driver()->firstOrFail(), $country), $total - $commission, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, [
            'reference_type' => 'marine_booking', 'reference_id' => $booking->id, 'idempotency_key' => 'marine:'.$booking->uuid.':earning',
            'notes' => ['key' => 'wallet.notes.marine_earning', 'variables' => ['title' => $listing->title]],
        ]);
        if ($commission > 0 && $listing->currency !== null) {
            $this->ledger->record('trip_commissions', FinancialEntryType::Income, $commission, $listing->currency, $country, $booking,
                ['key' => 'wallet.notes.marine_commission', 'variables' => ['title' => $listing->title]]);
        }

        return $total - $commission;
    }

    /** Two hires of the same boat never overlap. */
    private function assertFree(MarineListing $listing, Carbon $from, Carbon $to, ?int $except = null): void
    {
        $clash = $listing->bookings()->where(fn ($q) => $q->whereIn('status', ['confirmed', 'boarded'])
            ->orWhere(fn ($u) => $u->where('status', 'unpaid')->where('payment_expires_at', '>', now())))->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->exists();
        if ($clash) {
            throw new RidesException('marine_slot_taken', 409);
        }
    }

    private function assertOwner(Driver $driver, MarineListing $listing): void
    {
        if ($listing->driver_id !== $driver->id) {
            throw new RidesException('marine_not_found', 404);
        }
    }

    public function policy(int $countryId): RidePolicyData
    {
        $category = RideCategory::query()->where('slug', 'marine')->value('id');

        return $this->policies->for($countryId, null, null, $category !== null ? (int) $category : null);
    }
}
