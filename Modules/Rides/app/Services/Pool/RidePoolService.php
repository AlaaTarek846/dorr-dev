<?php

namespace Modules\Rides\Services\Pool;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCategory;
use Modules\Rides\Models\RidePool;
use Modules\Rides\Models\RidePoolSplit;
use Modules\Rides\Services\Drivers\DriverEligibility;
use Modules\Rides\Services\Notifications\RideNotifier;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Rides\Support\Geo;

/**
 * «أنا معك» — With Me (docs/rides_tasks.md O-30..O-33).
 *
 * Two ways a captain carries riders going his way:
 *  - **route**: he says where he's going and how far off his road he'll go (between the admin's
 *    `with_me.radius_min_m` and `with_me.radius_max_m`; equal = he can't change it). While the pool is
 *    open, With Me requests whose pickup and drop-off both lie in that corridor — in his direction —
 *    come to him, even mid-ride;
 *  - **ride**: a rider's With Me ride is accepted; once it starts, other With Me requests along it may join.
 *
 * Joining splits the money (the owner's example): the captain takes `with_me.driver_share_bp` of the
 * newcomer's price; the rest comes off everyone's fare — newcomer included — equally or in proportion to
 * the distance each shares with the newcomer (`with_me.split`), never taking a fare under
 * `with_me.min_pay_bp` of its price. Each rider and the captain are told what changed.
 */
class RidePoolService
{
    public const CATEGORY = 'with_me';

    public function __construct(
        private readonly RidePolicies $policies,
        private readonly DriverEligibility $eligibility,
        private readonly RideNotifier $notifier,
    ) {}

    /** The admin's corridor limits; `editable` false when min = max (the app then hides the control). @return array<string, mixed> */
    public function radiusLimits(int $countryId): array
    {
        $policy = $this->policies->for($countryId, null, null, $this->categoryId());
        $min = max(100, $policy->int('with_me.radius_min_m') ?: 1000);
        $max = max($min, $policy->int('with_me.radius_max_m') ?: 5000);

        return ['min_m' => $min, 'max_m' => $max, 'editable' => $min !== $max];
    }

    public function openFor(Driver $driver): ?RidePool
    {
        return RidePool::query()->where('driver_id', $driver->id)->where('status', 'open')->latest('id')->first();
    }

    /** Route mode: «I'm going there — take riders on my way». @param array{lat: float, lng: float, address?: ?string} $destination */
    public function openRoute(Driver $driver, array $destination, ?int $radiusM): RidePool
    {
        $vehicle = $this->eligibility->activeVehicle($driver);
        $category = RideCategory::query()->where('slug', self::CATEGORY)->first();
        if ($vehicle === null || $category === null || $this->eligibility->categoryProblem($driver, $vehicle, $category) !== null) {
            throw new RidesException('with_me_not_eligible', 422);
        }
        $limits = $this->radiusLimits((int) $driver->country_id);
        $radius = $limits['editable'] ? max($limits['min_m'], min($limits['max_m'], (int) ($radiusM ?? $limits['max_m']))) : $limits['max_m'];

        return DB::transaction(function () use ($driver, $destination, $radius, $vehicle) {
            RidePool::query()->where('driver_id', $driver->id)->where('status', 'open')->whereDoesntHave('activeRides')
                ->update(['status' => 'closed', 'closed_at' => now()]);
            $open = $this->openFor($driver);
            $fill = [
                'mode' => 'route', 'destination_lat' => $destination['lat'], 'destination_lng' => $destination['lng'],
                'destination_address' => $destination['address'] ?? null, 'radius_m' => $radius,
            ];
            if ($open !== null) {
                $open->fill($fill)->save();

                return $open->refresh();
            }

            return RidePool::query()->create($fill + ['driver_id' => $driver->id, 'seats' => max(1, (int) $vehicle->seats)]);
        });
    }

    /** The captain stops taking riders on the way (the ones in the car finish normally). */
    public function close(Driver $driver): void
    {
        RidePool::query()->where('driver_id', $driver->id)->where('status', 'open')->update(['status' => 'closed', 'closed_at' => now()]);
    }

    /** Ride mode: the captain took a rider's With Me ride — others on the way may join it once it starts. */
    public function startWith(Ride $ride, Driver $driver): void
    {
        if ($ride->category?->slug !== self::CATEGORY || $ride->pool_id !== null || $ride->destination_lat === null) {
            return;
        }
        $pool = $this->openFor($driver);
        if ($pool === null) {
            $vehicle = $this->eligibility->activeVehicle($driver);
            $pool = RidePool::query()->create([
                'driver_id' => $driver->id, 'mode' => 'ride',
                'destination_lat' => $ride->destination_lat, 'destination_lng' => $ride->destination_lng, 'destination_address' => $ride->destination_address,
                'radius_m' => $this->radiusLimits((int) $ride->country_id)['max_m'], 'seats' => max(1, (int) ($vehicle?->seats ?? 4)),
            ]);
        }
        $ride->forceFill(['pool_id' => $pool->id])->save();
    }

    /** A pool whose last rider got off, opened by a ride (not by the captain), closes by itself. */
    public function afterRideEnded(Ride $ride): void
    {
        $pool = $ride->pool_id !== null ? RidePool::query()->find($ride->pool_id) : null;
        if ($pool !== null && $pool->status === 'open' && $pool->mode === 'ride' && ! $pool->activeRides()->exists()) {
            $pool->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        }
    }

    /**
     * The open pools a With Me request fits: room in the car, the pickup and the drop-off both in the
     * corridor and in the captain's direction, and everyone's "who may share with me" respected.
     *
     * @return Collection<int, array{0: DriverPresence, 1: float}>
     */
    public function poolsFor(Ride $ride): Collection
    {
        if ($ride->category?->slug !== self::CATEGORY || $ride->destination_lat === null) {
            return collect();
        }
        $rider = $ride->rider()->first();

        return RidePool::query()->where('status', 'open')->with('driver')->get()
            ->map(function (RidePool $pool) use ($ride, $rider) {
                $presence = DriverPresence::query()->find($pool->driver_id);
                if ($presence === null || $presence->lat === null || ! $presence->gps_enabled) {
                    return null;
                }
                // A rider's ride: others join only once it started.
                if ($pool->mode === 'ride' && ! $pool->activeRides()->where('status', RideStatus::InProgress->value)->exists()) {
                    return null;
                }
                $active = $pool->activeRides()->get();
                if ((int) $active->sum(fn (Ride $r) => max(1, (int) $r->passengers)) + max(1, (int) $ride->passengers) > $pool->seats) {
                    return null;
                }
                if (! $this->inCorridor($pool, (float) $presence->lat, (float) $presence->lng, $ride)) {
                    return null;
                }
                if (! $this->compatible($active, $rider, $ride->share_with)) {
                    return null;
                }

                return [$presence, Geo::metres($ride->origin_lat, $ride->origin_lng, (float) $presence->lat, (float) $presence->lng)];
            })
            ->filter()
            ->sortBy(fn (array $pair) => $pair[1])
            ->values();
    }

    /** The newcomer is in the car's pool: split the money, tell everyone. */
    public function join(Ride $ride, Driver $driver): void
    {
        $pool = $this->openFor($driver);
        if ($pool === null) {
            return;
        }
        $others = $pool->activeRides()->where('id', '!=', $ride->id)->get();
        $ride->forceFill(['pool_id' => $pool->id])->save();
        if ($others->isEmpty()) {
            return; // first in the car: nothing to share yet
        }

        $policy = $this->policies->for((int) $ride->country_id, $ride->city_id, null, $this->categoryId());
        $price = (int) $ride->snapshot('breakdown.fare', 0);
        if ($price <= 0) {
            return;
        }
        $cut = (int) round($price * ($policy->int('with_me.driver_share_bp') ?: 2000) / 10000);
        $pot = $price - $cut;
        $method = $policy->get('with_me.split') === 'equal' ? 'equal' : 'proportional';
        $floorBp = $policy->int('with_me.min_pay_bp') ?: 5000;

        // Who shares, and how much of the newcomer's way each shares.
        $everyone = $others->push($ride);
        $newDistance = max(1, (int) ($ride->planned_distance_m ?: Geo::metres($ride->origin_lat, $ride->origin_lng, (float) $ride->destination_lat, (float) $ride->destination_lng)));
        $weights = $everyone->mapWithKeys(fn (Ride $r) => [$r->id => $method === 'equal' ? 1 : ($r->id === $ride->id
            ? $newDistance
            : max(1, min($newDistance, (int) Geo::metres($ride->origin_lat, $ride->origin_lng, (float) $r->destination_lat, (float) $r->destination_lng))))]);
        $sum = max(1, $weights->sum());

        $discounts = [];
        $given = 0;
        DB::transaction(function () use ($everyone, $weights, $sum, $pot, $floorBp, &$discounts, &$given, $pool, $ride, $price, $cut, $method) {
            foreach ($everyone as $r) {
                $fare = (int) $r->snapshot('breakdown.fare', 0);
                $room = max(0, $fare - (int) $r->pool_discount_minor - (int) ceil($fare * $floorBp / 10000));
                $share = min($room, (int) floor($pot * $weights[$r->id] / $sum));
                if ($share <= 0) {
                    continue;
                }
                Ride::query()->whereKey($r->id)->increment('pool_discount_minor', $share);
                $discounts[$r->id] = $share;
                $given += $share;
            }
            RidePoolSplit::query()->create([
                'ride_pool_id' => $pool->id, 'joining_ride_id' => $ride->id, 'price_minor' => $price,
                // What no rider could take (the floors) stays with the captain.
                'driver_cut_minor' => $cut + ($pot - $given), 'method' => $method, 'discounts' => $discounts, 'created_at' => now(),
            ]);
        });

        foreach ($everyone as $r) {
            $off = $discounts[$r->id] ?? 0;
            $fare = (int) $r->snapshot('breakdown.fare', 0);
            if ($r->id === $ride->id) {
                $this->notifier->rider($r, 'pool_joined', ['amount' => $this->notifier->money($fare - $off, $r), 'instead' => $this->notifier->money($fare, $r)]);
            } elseif ($off > 0) {
                $this->notifier->rider($r, 'pool_discount', ['amount' => $this->notifier->money($off, $r)]);
            }
            $this->notifier->changed($r->refresh(), 'pool_split');
        }
        $this->notifier->driver($driver, $ride, 'pool_earning', ['amount' => $this->notifier->money($cut + ($pot - $given), $ride)]);
    }

    /** Pickup and drop-off both within the corridor, the drop-off further along than the pickup. */
    private function inCorridor(RidePool $pool, float $lat, float $lng, Ride $ride): bool
    {
        [$dPick, $tPick] = $this->toSegment($lat, $lng, $pool->destination_lat, $pool->destination_lng, (float) $ride->origin_lat, (float) $ride->origin_lng);
        [$dDrop, $tDrop] = $this->toSegment($lat, $lng, $pool->destination_lat, $pool->destination_lng, (float) $ride->destination_lat, (float) $ride->destination_lng);

        return $dPick <= $pool->radius_m && $dDrop <= $pool->radius_m && $tDrop >= $tPick;
    }

    /**
     * Distance (metres) from a point to the segment A→B, and how far along it (0..1) — on a flat
     * local projection, plenty for city distances.
     *
     * @return array{0: float, 1: float}
     */
    private function toSegment(float $aLat, float $aLng, float $bLat, float $bLng, float $pLat, float $pLng): array
    {
        $k = cos(deg2rad(($aLat + $bLat) / 2)) * 111320;
        [$ax, $ay, $bx, $by, $px, $py] = [$aLng * $k, $aLat * 110540, $bLng * $k, $bLat * 110540, $pLng * $k, $pLat * 110540];
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $len = $dx * $dx + $dy * $dy;
        $t = $len > 0 ? max(0.0, min(1.0, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len)) : 0.0;

        return [hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy)), $t];
    }

    /**
     * O-33: each rider chose who may share with them — anyone, men only, women only — both ways.
     *
     * @param  Collection<int, Ride>  $active
     */
    private function compatible(Collection $active, $newRider, ?string $newWants): bool
    {
        $newGender = $newRider?->gender?->value;
        foreach ($active as $r) {
            $other = $r->rider()->first();
            $otherGender = $other?->gender?->value;
            if (! $this->allows($r->share_with, $newGender) || ! $this->allows($newWants, $otherGender)) {
                return false;
            }
        }

        return true;
    }

    private function allows(?string $wants, ?string $gender): bool
    {
        return match ($wants) {
            'male' => $gender === 'male',
            'female' => $gender === 'female',
            default => true,
        };
    }

    private function categoryId(): ?int
    {
        $id = RideCategory::query()->where('slug', self::CATEGORY)->value('id');

        return $id !== null ? (int) $id : null;
    }
}
