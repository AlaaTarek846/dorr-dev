<?php

namespace Modules\Rides\Services\Airport;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\Cache;
use Modules\Driver\Enums\PresenceStatus;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Rides\Enums\ZoneType;
use Modules\Rides\Models\RideZone;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Policy\RidePolicies;

/**
 * Where pickups are not allowed or only at approved points (D-282, D-283): the captain's list of those
 * zones, and a one-time alert when their phone crosses into a blocked one. The same alert at most every
 * `zones.alert_cooldown_s` per captain and zone, so driving along an edge never spams.
 */
class RestrictedZones
{
    public function __construct(
        private readonly RideFeatures $features,
        private readonly RidePolicies $policies,
        private readonly NotificationCenter $center,
    ) {}

    /**
     * The captain's country's blocked and pickup-only zones, to draw or list.
     *
     * @return list<array{id: int, type: string, name: ?string, polygon: list<list<float>>}>
     */
    public function listFor(Driver $driver): array
    {
        if ($driver->country_id === null || ! $this->features->allows((int) $driver->country_id, null, 'restricted_zones')) {
            return [];
        }

        return RideZone::query()->with('translations')->where('country_id', $driver->country_id)->where('status', true)
            ->whereIn('type', [ZoneType::Blocked->value, ZoneType::PickupOnly->value])->orderBy('id')->get()
            ->map(fn (RideZone $z) => ['id' => $z->id, 'type' => $z->type->value, 'name' => $z->translated('name'), 'polygon' => $z->polygon])->values()->all();
    }

    /** The captain's latest point is inside a blocked zone → they are told once (per cooldown). */
    public function onLocation(Driver $driver, float $lat, float $lng): void
    {
        if ($driver->country_id === null || ! $this->features->allows((int) $driver->country_id, null, 'restricted_zones')) {
            return;
        }
        if (DriverPresence::query()->find($driver->id)?->status !== PresenceStatus::Online) {
            return;
        }

        $zone = RideZone::query()->with('translations')->where('country_id', $driver->country_id)->where('type', ZoneType::Blocked->value)->where('status', true)
            ->where('min_lat', '<=', $lat)->where('max_lat', '>=', $lat)->where('min_lng', '<=', $lng)->where('max_lng', '>=', $lng)
            ->orderByDesc('priority')->get()->first(fn (RideZone $z) => $z->contains($lat, $lng));
        if ($zone === null) {
            return;
        }

        $cooldown = max(1, $this->policies->for($zone->country_id, $zone->city_id, $zone->id)->int('zones.alert_cooldown_s'));
        if (! Cache::add("rides:zone-alert:{$driver->id}:{$zone->id}", 1, $cooldown)) {
            return;
        }
        $this->center->send($driver, 'ride.zone_restricted', 'ride_zone_restricted_title', 'ride_zone_restricted_body', ['zone' => (string) $zone->translated('name')], ['type' => 'zone']);
    }
}
