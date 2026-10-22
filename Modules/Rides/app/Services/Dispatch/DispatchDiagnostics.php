<?php

namespace Modules\Rides\Services\Dispatch;

use Illuminate\Support\Facades\Cache;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\OfferStatus;
use Modules\Rides\Enums\RidePaymentMethod;
use Modules\Rides\Models\DriverPreference;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideActiveLock;
use Modules\Rides\Models\RideBlock;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Rides\Support\Geo;
use Modules\Wallet\Models\Wallet;

/**
 * «Why didn't the request reach me?» (docs/rides_tasks.md O-47). The same conditions DispatchEngine::candidates
 * applies, one by one, each with its own reason code — for one captain (are they ready at all?) and for one
 * ride (why each captain around it was or wasn't asked). Plus the server's own health: the dispatch tick
 * (rounds after the first need the scheduler) and real-time / push (a request a phone never hears about).
 */
class DispatchDiagnostics
{
    public const TICK_KEY = 'rides:dispatch_tick_at';

    public function __construct(
        private readonly DriverEligibility $eligibility,
        private readonly RidePolicies $policies,
        private readonly RideFeatures $features,
    ) {}

    /**
     * Is this captain ready to receive requests? Every check, passed or not, in the order that matters.
     *
     * @return array{ready: bool, checks: list<array{key: string, ok: bool, detail?: mixed}>}
     */
    public function driver(Driver $driver): array
    {
        $checks = [];
        $add = function (string $key, bool $ok, mixed $detail = null) use (&$checks) {
            $checks[] = ['key' => $key, 'ok' => $ok] + ($detail === null ? [] : ['detail' => $detail]);
        };
        $countryId = $driver->country_id;
        $policy = $countryId ? $this->policies->for((int) $countryId, null)->all() : [];

        $add('country', $countryId !== null);
        $add('service_on', $countryId !== null && $this->features->allows((int) $countryId, null, RideFeatures::SERVICE));
        $reason = $this->eligibility->reasonNotToWork($driver);
        $add('account', $reason === null, $reason);

        $presence = DriverPresence::query()->find($driver->id);
        $stale = (int) ($policy['driver.stale_after_s'] ?? 900);
        $add('online', $presence?->status === PresenceStatus::Online, $presence?->status?->value);
        $add('gps', (bool) $presence?->gps_enabled);
        $add('location', $presence?->lat !== null && $presence?->lng !== null);
        $seen = $presence?->last_seen_at;
        $add('location_fresh', $seen !== null && $seen->gte(now()->subSeconds($stale)), $seen === null ? null : ['seconds_ago' => (int) $seen->diffInSeconds(now()), 'limit_s' => $stale]);

        $vehicle = $this->eligibility->activeVehicle($driver);
        $categories = [];
        if ($vehicle !== null) {
            foreach (RideCategory::query()->where('status', true)->orderBy('sort_order')->get() as $category) {
                if ($countryId !== null && ! $this->features->allows((int) $countryId, null, RideFeatures::categoryFeature($category->slug))) {
                    continue;
                }
                $problem = $this->eligibility->categoryProblem($driver, $vehicle, $category) ?? ($this->eligibility->wants($driver, $category) ? null : 'switched_off_by_you');
                $categories[] = ['slug' => $category->slug, 'name' => $category->translated('name') ?? $category->slug, 'ok' => $problem === null, 'reason' => $problem];
            }
        }
        $add('categories', collect($categories)->contains('ok', true), $categories);

        $active = RideActiveLock::query()->find(RideActiveLock::forDriver($driver->id));
        $add('free', $active === null);
        $open = RideOffer::query()->where('driver_id', $driver->id)->where('status', OfferStatus::Sent->value)->where('expires_at', '>', now())->count();
        $max = max(1, (int) ($policy['search.max_open_offers_per_driver'] ?? 3));
        $add('offers_room', $open < $max, ['open' => $open, 'max' => $max]);

        $minBalance = (int) ($policy['driver.min_balance_minor'] ?? -5000);
        $balance = (int) Wallet::query()->where('owner_type', 'driver')->where('owner_id', $driver->id)->where('country_id', $countryId)->value('withdrawable_minor');
        $add('balance_for_cash', $balance >= $minBalance, ['balance_minor' => $balance, 'min_minor' => $minBalance]);

        $reach = DriverPreference::query()->whereKey($driver->id)->value('pickup_radius_m');
        $add('pickup_reach', $reach === null || (int) $reach >= 1000, $reach === null ? null : ['metres' => (int) $reach]);

        foreach ($this->server() as $key => $ok) {
            $add($key, $ok);
        }

        return ['ready' => collect($checks)->every('ok'), 'checks' => $checks];
    }

    /**
     * For one ride: every captain around it (online or not) and why they were or weren't asked.
     *
     * @return array<string, mixed>
     */
    public function ride(Ride $ride): array
    {
        $policy = (array) $ride->snapshot('policy', []);
        $maxRadius = (int) ($policy['search.max_radius_m'] ?? 50000);
        $radius = (int) ($ride->search_radius_m ?: ($policy['search.min_radius_m'] ?? 5000));
        $stale = (int) ($policy['driver.stale_after_s'] ?? 900);
        $minBalance = (int) ($policy['driver.min_balance_minor'] ?? -5000);
        $maxOpen = max(1, (int) ($policy['search.max_open_offers_per_driver'] ?? 3));
        $category = $ride->category()->first();
        $riderPhone = $ride->rider()->value('phone');
        $offers = RideOffer::query()->where('ride_id', $ride->id)->get()->keyBy('driver_id');
        $released = $ride->events()->where('type', 'driver_released')->pluck('actor_id')->map(fn ($id) => (int) $id)->all();

        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boxAround($ride->origin_lat, $ride->origin_lng, $maxRadius * 2);
        $presences = DriverPresence::query()->whereBetween('lat', [$minLat, $maxLat])->whereBetween('lng', [$minLng, $maxLng])->limit(80)->get();

        $captains = $presences->map(function (DriverPresence $p) use ($ride, $category, $riderPhone, $offers, $released, $radius, $stale, $minBalance, $maxOpen) {
            $driver = Driver::query()->find($p->driver_id);
            $distance = (int) round(Geo::metres($ride->origin_lat, $ride->origin_lng, (float) $p->lat, (float) $p->lng));
            $offer = $offers->get($p->driver_id);
            $reason = match (true) {
                $driver === null => 'unknown_driver',
                $offer !== null => 'offered_'.($offer->status instanceof \BackedEnum ? $offer->status->value : $offer->status),
                $driver->phone === $riderPhone => 'same_phone_as_rider',
                $p->status !== PresenceStatus::Online => 'offline',
                ! $p->gps_enabled => 'gps_off',
                $p->last_seen_at === null || $p->last_seen_at->lt(now()->subSeconds($stale)) => 'location_stale',
                $distance > $radius => 'outside_search_radius',
                ($r = DriverPreference::query()->whereKey($driver->id)->value('pickup_radius_m')) !== null && $distance > (int) $r => 'beyond_own_pickup_reach',
                in_array($driver->id, $released, true) => 'stepped_back_from_this_ride',
                $ride->search_scope === 'circle' && ! in_array($driver->id, array_map('intval', (array) $ride->circle_driver_ids), true) => 'circle_only',
                RideActiveLock::query()->whereKey(RideActiveLock::forDriver($driver->id))->exists() => 'on_another_ride',
                RideOffer::query()->where('driver_id', $driver->id)->where('status', OfferStatus::Sent->value)->where('expires_at', '>', now())->count() >= $maxOpen => 'too_many_open_requests',
                RideBlock::query()->where(fn ($q) => $q->where('blocker_type', 'user')->where('blocker_id', $ride->user_id)->where('blocked_type', 'driver')->where('blocked_id', $driver->id))
                    ->orWhere(fn ($q) => $q->where('blocker_type', 'driver')->where('blocker_id', $driver->id)->where('blocked_type', 'user')->where('blocked_id', $ride->user_id))->exists() => 'blocked',
                ($why = $this->eligibility->reasonNotToWork($driver)) !== null => $why,
                ($vehicle = $this->eligibility->activeVehicle($driver)) === null => 'no_active_vehicle',
                $category !== null && ($problem = $this->eligibility->categoryProblem($driver, $vehicle, $category)) !== null => $problem,
                $category !== null && ! $this->eligibility->wants($driver, $category) => 'category_switched_off_by_driver',
                $ride->payment_method === RidePaymentMethod::Cash && (int) Wallet::query()->where('owner_type', 'driver')->where('owner_id', $driver->id)->where('country_id', $ride->country_id)->value('withdrawable_minor') < $minBalance => 'balance_too_low_for_cash',
                default => 'eligible_waiting_next_round',
            };

            return [
                'driver_id' => $p->driver_id, 'name' => $driver?->name, 'phone' => $driver?->phone,
                'distance_m' => $distance, 'presence' => $p->status?->value, 'gps' => (bool) $p->gps_enabled,
                'last_seen_at' => $p->last_seen_at?->toIso8601String(), 'reason' => $reason,
            ];
        })->sortBy('distance_m')->values();

        return [
            'ride' => [
                'id' => $ride->id, 'number' => $ride->number, 'status' => $ride->status->value, 'category' => $category?->slug,
                'payment_method' => $ride->payment_method?->value, 'round' => (int) $ride->dispatch_round,
                'search_radius_m' => $radius, 'max_radius_m' => $maxRadius, 'search_scope' => $ride->search_scope,
                'requested_at' => $ride->requested_at?->toIso8601String(), 'next_dispatch_at' => $ride->next_dispatch_at?->toIso8601String(),
            ],
            'server' => $this->server(),
            'tick_at' => Cache::get(self::TICK_KEY),
            'captains' => $captains,
        ];
    }

    /** @return array<string, bool> the server's side: the scheduler (later rounds), real time and push (the phone hears it). */
    public function server(): array
    {
        $tick = Cache::get(self::TICK_KEY);
        $broadcaster = (string) config('broadcasting.default');

        return [
            'server_dispatch_tick' => $tick !== null && now()->diffInSeconds(\Illuminate\Support\Carbon::parse($tick)) < 120,
            'server_realtime' => in_array($broadcaster, ['pusher', 'reverb'], true) && ! empty(config("broadcasting.connections.{$broadcaster}.key")),
            'server_push' => (bool) (config('services.onesignal.driver_app_id') || config('services.onesignal.app_id')),
        ];
    }
}
