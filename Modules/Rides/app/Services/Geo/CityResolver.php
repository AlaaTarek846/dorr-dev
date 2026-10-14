<?php

namespace Modules\Rides\Services\Geo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Rides\Enums\ZoneType;
use Modules\Rides\Models\RideZone;
use Modules\Rides\Support\Geo;
use Modules\Rides\Support\PlaceName;

/**
 * Which admin city a point is in (docs/rides-structure.md §0.4), in this order:
 *  1. Google's city name for the point (Geocoding, cached) matched to one of our cities by name;
 *  2. otherwise a service-area polygon;
 *  3. otherwise the nearest Discover city within `rides.city_radius_km` — the same city list the
 *     chat's city rules use.
 * So when the Google key runs out or Google can't tell, the city still comes out.
 */
class CityResolver
{
    private const CACHE = 'rides:cities';

    public function __construct(private readonly GeoProvider $geo) {}

    public function cityAt(float $lat, float $lng): ?int
    {
        return $this->byGoogleName($lat, $lng) ?? $this->byZone($lat, $lng) ?? $this->nearest($lat, $lng);
    }

    /** The country a city belongs to (null when the city list has no such city). */
    public function countryOf(int $cityId): ?int
    {
        $city = collect($this->cities())->firstWhere('id', $cityId);

        return $city['country_id'] ?? null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE);
    }

    private function byGoogleName(float $lat, float $lng): ?int
    {
        $name = $this->geo->cityName($lat, $lng);
        if ($name === null) {
            return null;
        }

        // Same name in two countries (rare): the one nearest the point.
        return collect($this->cities())
            ->filter(fn (array $city) => collect($city['names'])->contains(fn (string $n) => PlaceName::same($n, $name)))
            ->sortBy(fn (array $city) => Geo::metres($lat, $lng, $city['lat'], $city['lng']))
            ->first()['id'] ?? null;
    }

    private function byZone(float $lat, float $lng): ?int
    {
        return RideZone::query()
            ->where('type', ZoneType::ServiceArea->value)
            ->where('status', true)
            ->whereNotNull('city_id')
            ->where('min_lat', '<=', $lat)->where('max_lat', '>=', $lat)
            ->where('min_lng', '<=', $lng)->where('max_lng', '>=', $lng)
            ->orderByDesc('priority')
            ->get()
            ->first(fn (RideZone $zone) => $zone->contains($lat, $lng))
            ?->city_id;
    }

    private function nearest(float $lat, float $lng): ?int
    {
        $radius = (float) config('rides.city_radius_km', 60) * 1000;

        return collect($this->cities())
            ->map(fn (array $city) => [$city['id'], Geo::metres($lat, $lng, $city['lat'], $city['lng'])])
            ->filter(fn (array $pair) => $pair[1] <= $radius)
            ->sortBy(fn (array $pair) => $pair[1])
            ->first()[0] ?? null;
    }

    /** @return list<array{id: int, country_id: int, lat: float, lng: float, names: list<string>}> */
    private function cities(): array
    {
        $class = 'Modules\\Discover\\Models\\DiscoverCity';
        if (! class_exists($class)) {
            return [];
        }

        return Cache::remember(self::CACHE, 300, fn () => $class::query()
            ->where('status', true)->whereNotNull('lat')->whereNotNull('lng')
            ->with('translations')
            ->get()
            ->map(fn (Model $c) => [
                'id' => (int) $c->getKey(),
                'country_id' => (int) $c->getAttribute('country_id'),
                'lat' => (float) $c->getAttribute('lat'),
                'lng' => (float) $c->getAttribute('lng'),
                'names' => $c->getRelation('translations')->pluck('name')->filter()->values()->all(),
            ])
            ->all());
    }
}
