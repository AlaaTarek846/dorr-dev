<?php

namespace Modules\Rides\Services\Hire;

use App\Models\Country;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverHire;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
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
 * Find a driver (docs/rides_tasks.md O-26c). The rider has the car; a captain drives it.
 *
 *  - Priced from the policy: `hire.hour_price_minor` × hours or `hire.day_price_minor` × days, paid upfront
 *    from the wallet (non-withdrawable money first).
 *  - The online captains within `hire.radius_m` are told at once (`hire.new`); the first to accept takes it.
 *  - The captain arrives, starts, ends; minutes past the time booked add `hire.overtime_per_min_minor`
 *    (taken from the wallet, or left as due when it can't cover them).
 *  - The rider cancels free while nobody accepted, or up to `hire.free_cancel_before_s` before the start;
 *    a request nobody took by its start time expires and is refunded (checked whenever the lists load —
 *    nothing runs on a timer for it).
 *  - The captain is paid at the end, less `hire.commission_bp`.
 */
class DriverHireService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly NotificationCenter $notifications,
        private readonly RidePolicies $policies,
        private readonly RideFeatures $features,
        private readonly CityResolver $cities,
        private readonly FinancialLedgerService $ledger,
    ) {}

    // ---------------------------------------------------------------- the rider

    /**
     * The price for a place, a unit and a quantity (and the limits, so the app builds its pickers).
     *
     * @return array<string, mixed>
     */
    public function quote(User $rider, float $lat, float $lng, string $unit, int $quantity): array
    {
        [$country, $policy] = $this->placeOf($rider, $lat, $lng);
        $limits = $this->limits($policy);
        $price = $unit === 'day' ? $limits['day_price_minor'] : $limits['hour_price_minor'];
        if ($price <= 0) {
            throw new RidesException('hire_unit_unavailable', 422);
        }
        $max = $unit === 'day' ? $limits['max_days'] : $limits['max_hours'];
        $min = $unit === 'day' ? 1 : $limits['min_hours'];
        if ($quantity < $min || $quantity > $max) {
            throw new RidesException('hire_quantity_range', 422, ['min' => $min, 'max' => $max], ['min' => $min, 'max' => $max]);
        }

        return ['country' => $country, 'amount_minor' => $price * $quantity, 'limits' => $limits];
    }

    /** @param array<string, mixed> $data */
    public function request(User $rider, array $data, ?string $key): DriverHire
    {
        if ($key !== null && ($done = DriverHire::query()->where('user_id', $rider->id)->where('idempotency_key', $key)->first()) !== null) {
            return $done;
        }
        if (DriverHire::query()->where('user_id', $rider->id)->whereIn('status', ['open', ...DriverHire::LIVE])->exists()) {
            throw new RidesException('hire_already_open', 409);
        }
        $q = $this->quote($rider, (float) $data['pickup']['lat'], (float) $data['pickup']['lng'], $data['unit'], (int) $data['quantity']);
        /** @var Country $country */
        $country = $q['country'];
        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : now();
        if ($startsAt->lt(now()->subMinutes(5)) || $startsAt->gt(now()->addDays(30))) {
            throw new RidesException('bus_time_past', 422);
        }

        $hire = DB::transaction(function () use ($rider, $data, $key, $q, $country, $startsAt) {
            $hire = DriverHire::query()->create([
                'user_id' => $rider->id, 'country_id' => $country->id, 'currency_id' => $country->currency_id,
                'pickup_lat' => $data['pickup']['lat'], 'pickup_lng' => $data['pickup']['lng'], 'pickup_address' => $data['pickup']['address'],
                'starts_at' => $startsAt->lt(now()) ? now() : $startsAt, 'unit' => $data['unit'], 'quantity' => (int) $data['quantity'],
                'transmission' => $data['transmission'] ?? 'automatic', 'car' => $data['car'] ?? null, 'note' => $data['note'] ?? null,
                'amount_minor' => $q['amount_minor'], 'idempotency_key' => $key,
            ]);
            $hire->forceFill(['wallet_transaction_ids' => $this->charge($rider, $hire, $q['amount_minor'], 'hire:'.$hire->uuid.':pay')])->save();

            return $hire;
        });

        $this->tellCaptains($hire);

        return $hire->refresh();
    }

    public function cancelByRider(User $rider, DriverHire $hire): DriverHire
    {
        if ($hire->user_id !== $rider->id || ! in_array($hire->status, ['open', 'accepted'], true)) {
            throw new RidesException('hire_not_editable', 409);
        }
        $window = $this->policy($hire->country_id)->int('hire.free_cancel_before_s') ?: 3600;
        if ($hire->status === 'accepted' && now()->addSeconds($window)->greaterThan($hire->starts_at)) {
            throw new RidesException('hire_too_late', 409, ['minutes' => intdiv($window, 60)]);
        }
        $driver = $hire->driver;
        DB::transaction(fn () => $this->refund($hire, 'rider'));
        if ($driver !== null) {
            $this->notifications->send($driver, 'hire.canceled', 'hire_canceled_title', 'hire_canceled_by_rider_body', ['address' => $hire->pickup_address], ['type' => 'hire', 'hire_uuid' => $hire->uuid]);
        }

        return $hire->refresh();
    }

    /** @return Collection<int, DriverHire> */
    public function mineAsRider(User $rider): Collection
    {
        $this->expireStale();

        return DriverHire::query()->where('user_id', $rider->id)->latest('id')->limit(50)->get();
    }

    // ---------------------------------------------------------------- the captain

    /** @return Collection<int, DriverHire> open requests near me (from my last known place), soonest first */
    public function openNear(Driver $driver): Collection
    {
        $this->assertCanDrive($driver);
        $this->expireStale();
        $here = DriverPresence::query()->where('driver_id', $driver->id)->first();

        return DriverHire::query()->where('status', 'open')->when($driver->country_id, fn ($q, $c) => $q->where('country_id', $c))
            ->orderBy('starts_at')->limit(100)->get()
            ->filter(fn (DriverHire $h) => $here === null || $here->lat === null
                || Geo::metres((float) $here->lat, (float) $here->lng, $h->pickup_lat, $h->pickup_lng) <= ($this->policy($h->country_id)->int('hire.radius_m') ?: 30000))
            ->values();
    }

    public function accept(Driver $driver, DriverHire $hire): DriverHire
    {
        $this->assertCanDrive($driver);
        if (DriverHire::query()->where('driver_id', $driver->id)->whereIn('status', DriverHire::LIVE)
            ->where('starts_at', '<', $hire->starts_at->copy()->addSeconds($hire->bookedSeconds()))->exists()) {
            throw new RidesException('hire_driver_busy', 409);
        }
        $taken = DB::transaction(function () use ($driver, $hire) {
            $fresh = DriverHire::query()->whereKey($hire->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status !== 'open') {
                return false;
            }
            $fresh->forceFill(['status' => 'accepted', 'driver_id' => $driver->id, 'accepted_at' => now()])->save();

            return true;
        });
        if (! $taken) {
            throw new RidesException('hire_taken', 409);
        }
        $hire->refresh();
        $this->notifications->send($hire->user, 'hire.accepted', 'hire_accepted_title', 'hire_accepted_body', [
            'name' => $driver->name ?? '', 'time' => $hire->starts_at->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i'),
        ], ['type' => 'hire', 'hire_uuid' => $hire->uuid]);

        return $hire;
    }

    /** accepted → arrived → started → completed (paid). */
    public function move(Driver $driver, DriverHire $hire, string $to): DriverHire
    {
        if ($hire->driver_id !== $driver->id) {
            throw new RidesException('hire_not_found', 404);
        }
        $from = ['arrived' => 'accepted', 'started' => 'arrived', 'completed' => 'started'][$to] ?? null;
        if ($from === null || $hire->status !== $from) {
            throw new RidesException('hire_not_editable', 409);
        }
        DB::transaction(function () use ($hire, $to) {
            $hire->forceFill(['status' => $to, $to === 'completed' ? 'ended_at' : $to.'_at' => now()])->save();
            if ($to === 'completed') {
                $this->settle($hire);
            }
        });
        $hire->refresh();
        $this->notifications->send($hire->user, 'hire.'.$to, 'hire_'.$to.'_title', 'hire_'.$to.'_body', [
            'name' => $driver->name ?? '',
        ], ['type' => 'hire', 'hire_uuid' => $hire->uuid]);

        return $hire;
    }

    /** The captain steps back before starting: the request opens again for the others. */
    public function release(Driver $driver, DriverHire $hire): DriverHire
    {
        if ($hire->driver_id !== $driver->id || ! in_array($hire->status, ['accepted', 'arrived'], true)) {
            throw new RidesException('hire_not_editable', 409);
        }
        $hire->forceFill(['status' => 'open', 'driver_id' => null, 'accepted_at' => null, 'arrived_at' => null])->save();
        $this->notifications->send($hire->user, 'hire.released', 'hire_released_title', 'hire_released_body', [], ['type' => 'hire', 'hire_uuid' => $hire->uuid]);
        $this->tellCaptains($hire, $driver->id);

        return $hire->refresh();
    }

    /** @return Collection<int, DriverHire> */
    public function mineAsDriver(Driver $driver): Collection
    {
        return DriverHire::query()->where('driver_id', $driver->id)->latest('starts_at')->limit(50)->get();
    }

    // ---------------------------------------------------------------- rules and money

    /** @return array{hour_price_minor: int, day_price_minor: int, min_hours: int, max_hours: int, max_days: int, overtime_per_min_minor: int, free_cancel_before_s: int} */
    public function limits(RidePolicyData $policy): array
    {
        return [
            'hour_price_minor' => $policy->int('hire.hour_price_minor'),
            'day_price_minor' => $policy->int('hire.day_price_minor'),
            'min_hours' => max(1, $policy->int('hire.min_hours')),
            'max_hours' => max(1, $policy->int('hire.max_hours')),
            'max_days' => max(1, $policy->int('hire.max_days')),
            'overtime_per_min_minor' => $policy->int('hire.overtime_per_min_minor'),
            'free_cancel_before_s' => $policy->int('hire.free_cancel_before_s'),
        ];
    }

    /** @return array{0: Country, 1: RidePolicyData} */
    public function placeOf(User $rider, float $lat, float $lng): array
    {
        $cityId = $this->cities->cityAt($lat, $lng);
        $countryId = ($cityId !== null ? $this->cities->countryOf($cityId) : null) ?? $rider->country_id;
        $country = $countryId !== null ? Country::query()->find($countryId) : null;
        if ($country === null || ! $this->features->allows($country->id, $cityId, 'find_driver')) {
            throw RidesException::featureDisabled('find_driver');
        }

        return [$country, $this->policies->for($country->id, $cityId)];
    }

    private function policy(int $countryId): RidePolicyData
    {
        return $this->policies->for($countryId, null);
    }

    private function assertCanDrive(Driver $driver): void
    {
        $status = $driver->status instanceof \BackedEnum ? $driver->status->value : $driver->status;
        if ($status !== DriverStatus::Active->value) {
            throw new RidesException('hire_driver_not_active', 403);
        }
    }

    /** The online captains near the pickup hear about it at once (push + real time) — no app asks on a timer. */
    private function tellCaptains(DriverHire $hire, ?int $except = null): void
    {
        $radius = $this->policy($hire->country_id)->int('hire.radius_m') ?: 30000;
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($hire->pickup_lat, $hire->pickup_lng, $radius);
        $ids = DriverPresence::query()->where('status', PresenceStatus::Online->value)
            ->where('last_seen_at', '>=', now()->subSeconds(900))
            ->whereBetween('lat', [$minLat, $maxLat])->whereBetween('lng', [$minLng, $maxLng])
            ->when($except, fn ($q, $id) => $q->where('driver_id', '!=', $id))
            ->limit(50)->pluck('driver_id');
        $drivers = Driver::query()->whereIn('id', $ids)->where('status', DriverStatus::Active->value)->get();
        if ($drivers->isNotEmpty()) {
            $this->notifications->send($drivers->all(), 'hire.new', 'hire_new_title', 'hire_new_body', [
                'address' => $hire->pickup_address, 'length' => $hire->quantity.($hire->unit === 'day' ? 'd' : 'h'),
            ], ['type' => 'hire', 'hire_uuid' => $hire->uuid]);
        }
    }

    /** Requests nobody took by their start time: expired and refunded. */
    private function expireStale(): void
    {
        DriverHire::query()->where('status', 'open')->where('starts_at', '<', now()->subMinutes(15))->limit(50)->get()
            ->each(function (DriverHire $h) {
                DB::transaction(fn () => $this->refund($h, 'system', 'expired'));
                $this->notifications->send($h->user, 'hire.expired', 'hire_expired_title', 'hire_expired_body', [], ['type' => 'hire', 'hire_uuid' => $h->uuid]);
            });
    }

    /** @return list<int> */
    private function charge(User $rider, DriverHire $hire, int $amount, string $key): array
    {
        $wallet = $this->wallets->firstOrCreateWallet($rider, $hire->country()->firstOrFail())->fresh();
        $free = max(0, $wallet->availableMinor(WalletBucket::SpendOnly)) + max(0, $wallet->availableMinor(WalletBucket::Withdrawable));
        if ($free < $amount) {
            throw new RidesException('insufficient_balance', 422, [], ['shortfall_minor' => $amount - $free]);
        }
        $this->wallets->debitSpendOnlyFirst($wallet, $amount, WalletTransactionType::ServicePayment, [
            'reference_type' => 'driver_hire', 'reference_id' => $hire->id, 'idempotency_key' => $key,
            'notes' => ['key' => 'wallet.notes.hire_payment', 'variables' => ['address' => $hire->pickup_address]],
        ]);

        return WalletTransaction::query()->whereIn('idempotency_key', [$key, $key.':spend_only', $key.':withdrawable'])->pluck('id')->all();
    }

    private function refund(DriverHire $hire, string $by, string $status = 'canceled'): void
    {
        foreach (WalletTransaction::query()->whereIn('id', (array) $hire->wallet_transaction_ids)->get() as $tx) {
            if (! $tx->isReversed()) {
                $this->wallets->reverse($tx, 'hire_canceled', ['idempotency_key' => 'hire:'.$hire->uuid.':refund:'.$tx->id]);
            }
        }
        $hire->forceFill(['status' => $status, 'canceled_at' => now(), 'canceled_by' => $by])->save();
    }

    /** The end: minutes past the time booked, then the captain's pay less the commission. */
    private function settle(DriverHire $hire): void
    {
        $policy = $this->policy($hire->country_id);
        $over = $hire->started_at === null ? 0 : (int) ceil(max(0, $hire->started_at->diffInSeconds(now()) - $hire->bookedSeconds()) / 60);
        $extra = $over * $policy->int('hire.overtime_per_min_minor');
        $due = 0;
        if ($extra > 0) {
            try {
                $ids = $this->charge($hire->user()->firstOrFail(), $hire, $extra, 'hire:'.$hire->uuid.':extra');
                $hire->wallet_transaction_ids = array_merge((array) $hire->wallet_transaction_ids, $ids);
            } catch (RidesException) {
                $due = $extra;
                $extra = 0;
            }
        }
        $hire->forceFill(['extra_minor' => $extra, 'extra_due_minor' => $due])->save();

        $total = $hire->amount_minor + $extra;
        $commission = (int) round($total * $policy->int('hire.commission_bp') / 10000);
        $country = $hire->country()->firstOrFail();
        $this->wallets->credit($this->wallets->firstOrCreateWallet($hire->driver()->firstOrFail(), $country), $total - $commission, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, [
            'reference_type' => 'driver_hire', 'reference_id' => $hire->id, 'idempotency_key' => 'hire:'.$hire->uuid.':earning',
            'notes' => ['key' => 'wallet.notes.hire_earning', 'variables' => ['address' => $hire->pickup_address]],
        ]);
        if ($commission > 0 && $hire->currency !== null) {
            $this->ledger->record('trip_commissions', FinancialEntryType::Income, $commission, $hire->currency, $country, $hire,
                ['key' => 'wallet.notes.hire_commission', 'variables' => ['address' => $hire->pickup_address]]);
        }
    }
}
