<?php

namespace Modules\Rides\Services\Geo;

use Illuminate\Support\Collection;
use Modules\Rides\Enums\PickupPointKind;
use Modules\Rides\Enums\ZoneType;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\RidePickupPoint;
use Modules\Rides\Models\RideZone;
use Modules\Rides\Support\Geo;

/**
 * Approved pickup / drop-off points for sensitive places (R-16..R-18, D-111). A place is governed by
 * the highest-priority `airport` or `pickup_only` zone around it. In such a zone a rider may — or, when
 * the zone says so, must — pick one of its approved points; the booking then starts (or ends) exactly
 * at that point, and the captain is sent to it.
 *
 * A zone requires a point when it is `pickup_only` (pickups), or its `config` says `pickup_required` /
 * `dropoff_required`. A zone with no active point for that purpose requires nothing — otherwise nobody
 * could ride there.
 */
class PickupPoints
{
    public const PICKUP = 'pickup';

    public const DROPOFF = 'dropoff';

    /** The airport / pickup-only zone around a place, the highest priority first. */
    public function zoneAt(int $countryId, float $lat, float $lng): ?RideZone
    {
        return RideZone::query()
            ->where('country_id', $countryId)->whereIn('type', [ZoneType::Airport->value, ZoneType::PickupOnly->value])->where('status', true)
            ->where('min_lat', '<=', $lat)->where('max_lat', '>=', $lat)
            ->where('min_lng', '<=', $lng)->where('max_lng', '>=', $lng)
            ->with('translations')->orderByDesc('priority')->orderBy('id')
            ->get()
            ->first(fn (RideZone $zone) => $zone->contains($lat, $lng));
    }

    /** @return Collection<int, RidePickupPoint> the zone's active points for this purpose, in the admin's order */
    public function pointsOf(RideZone $zone, string $purpose): Collection
    {
        return RidePickupPoint::query()->where('zone_id', $zone->id)->where('status', true)
            ->where($purpose === self::DROPOFF ? 'for_dropoff' : 'for_pickup', true)
            ->with('translations')->orderBy('sort_order')->orderBy('id')->get();
    }

    public static function required(RideZone $zone, string $purpose): bool
    {
        $config = (array) $zone->config;

        return $purpose === self::DROPOFF
            ? (bool) ($config['dropoff_required'] ?? false)
            : $zone->type === ZoneType::PickupOnly || (bool) ($config['pickup_required'] ?? false);
    }

    /**
     * What the rider's app shows at a place: the zone, whether a point is a must, the points with how
     * far each is from where the rider stands.
     *
     * @return array{zone: array<string, mixed>|null, required: bool, points: list<array<string, mixed>>}
     */
    public function around(int $countryId, float $lat, float $lng, string $purpose): array
    {
        $zone = $this->zoneAt($countryId, $lat, $lng);
        $points = $zone === null ? collect() : $this->pointsOf($zone, $purpose);
        if ($zone === null || $points->isEmpty()) {
            return ['zone' => null, 'required' => false, 'points' => []];
        }

        return [
            'zone' => $this->presentZone($zone),
            'required' => self::required($zone, $purpose),
            'points' => $points->map(fn (RidePickupPoint $p) => self::present(self::snapshot($p), (int) round(Geo::metres($lat, $lng, $p->lat, $p->lng))))->values()->all(),
        ];
    }

    /**
     * The place of a trip end, moved onto its chosen approved point.
     *
     * `point_id` picks one; without it, a point within `$matchM` metres of the place is taken. In a zone
     * that requires a point, neither → 422 with the points to choose from.
     *
     * @param  array{lat: float|int|string, lng: float|int|string, address?: ?string, point_id?: ?int}  $place
     * @return array{place: array<string, mixed>, point: array<string, mixed>|null, zone: RideZone|null}
     */
    public function resolve(int $countryId, array $place, string $purpose, int $matchM): array
    {
        $chosenId = isset($place['point_id']) ? (int) $place['point_id'] : null;
        unset($place['point_id']);
        $lat = (float) $place['lat'];
        $lng = (float) $place['lng'];

        $zone = $this->zoneAt($countryId, $lat, $lng);
        $points = $zone === null ? collect() : $this->pointsOf($zone, $purpose);

        if ($chosenId !== null) {
            $chosen = $points->firstWhere('id', $chosenId) ?? throw new RidesException('pickup_point_invalid', 422);
        } else {
            $chosen = $points->map(fn (RidePickupPoint $p) => [$p, Geo::metres($lat, $lng, $p->lat, $p->lng)])
                ->filter(fn (array $pair) => $pair[1] <= $matchM)->sortBy(1)->map(fn (array $pair) => $pair[0])->first();
        }

        if ($chosen === null) {
            if ($zone !== null && $points->isNotEmpty() && self::required($zone, $purpose)) {
                $around = $this->around($countryId, $lat, $lng, $purpose);
                throw new RidesException($purpose === self::DROPOFF ? 'dropoff_point_required' : 'pickup_point_required', 422, ['zone' => (string) $zone->translated('name')], $around);
            }

            return ['place' => $place, 'point' => null, 'zone' => $zone];
        }

        $snapshot = self::snapshot($chosen);
        $place['lat'] = $chosen->lat;
        $place['lng'] = $chosen->lng;
        $place['address'] = self::present($snapshot)['name'];

        return ['place' => $place, 'point' => $snapshot, 'zone' => $zone];
    }

    /**
     * The point as frozen with a quote and its ride: both languages, so each side reads it in their own.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(RidePickupPoint $point): array
    {
        $point->loadMissing('translations');

        return [
            'id' => $point->id,
            'zone_id' => $point->zone_id,
            'kind' => $point->kind->value,
            'lat' => $point->lat,
            'lng' => $point->lng,
            'name' => $point->translations->pluck('name', 'locale')->all(),
            'instructions' => $point->translations->pluck('instructions', 'locale')->filter()->all(),
        ];
    }

    /**
     * A frozen point in the reader's language.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    public static function present(?array $snapshot, ?int $distanceM = null): ?array
    {
        if (empty($snapshot['id'])) {
            return null;
        }
        $pick = function (array $map): ?string {
            return $map[app()->getLocale()] ?? $map['en'] ?? (reset($map) ?: null);
        };
        $kind = PickupPointKind::tryFrom((string) ($snapshot['kind'] ?? ''));

        return [
            'id' => (int) $snapshot['id'],
            'kind' => $kind?->value,
            'kind_label' => $kind === null ? null : __('rides.point_kinds.'.$kind->value),
            'name' => $pick((array) ($snapshot['name'] ?? [])),
            'instructions' => $pick((array) ($snapshot['instructions'] ?? [])),
            'lat' => (float) $snapshot['lat'],
            'lng' => (float) $snapshot['lng'],
        ] + ($distanceM === null ? [] : ['distance_m' => $distanceM]);
    }

    /** @return array{id: int, type: string, name: ?string} */
    private function presentZone(RideZone $zone): array
    {
        $zone->loadMissing('translations');

        return ['id' => $zone->id, 'type' => $zone->type->value, 'name' => $zone->translated('name')];
    }
}
