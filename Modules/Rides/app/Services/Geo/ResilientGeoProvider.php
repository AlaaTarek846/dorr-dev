<?php

namespace Modules\Rides\Services\Geo;

use Illuminate\Support\Facades\Cache;
use Modules\Rides\Models\RideSetting;
use Modules\Rides\Support\Geo;

/**
 * What the app uses for maps (O-01, O-02): the provider the admin chose — Google (Routes + Geocoding) or
 * Mapbox (Directions + Geocoding), one of them; the free OpenStreetMap fallback is gone. When it can't
 * answer (no key yet, the quota ran out, a refusal, no connection), the estimate Jawad uses —
 * straight-line distance with a road factor, at an average speed (both set in the admin).
 * A ride is never blocked on a map provider.
 *
 * City names are cached per ~1 km cell (`geocode_cache_days`), so each place is looked up once.
 */
class ResilientGeoProvider implements GeoProvider
{
    private const VERSION = 'rides:city-name-version';

    public function __construct(private readonly GoogleGeoProvider $google, private readonly MapboxGeoProvider $mapbox) {}

    private function chosen(): GeoProvider
    {
        return RideSetting::current()->mapProvider() === 'mapbox' ? $this->mapbox : $this->google;
    }

    /** Drops every cached city name at once (a new key): the next lookups ask Google again. */
    public static function forgetCityNames(): void
    {
        Cache::forever(self::VERSION, (int) Cache::get(self::VERSION, 1) + 1);
    }

    public function route(float $originLat, float $originLng, float $destinationLat, float $destinationLng, array $stops = []): ?RouteResult
    {
        return $this->chosen()->route($originLat, $originLng, $destinationLat, $destinationLng, $stops)
            ?? $this->estimate($originLat, $originLng, $destinationLat, $destinationLng, $stops);
    }

    public function cityName(float $lat, float $lng): ?string
    {
        $key = sprintf('rides:city-name:%d:%.2f,%.2f', (int) Cache::get(self::VERSION, 1), $lat, $lng);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }

        $name = $this->chosen()->cityName($lat, $lng);
        // A miss is remembered briefly only, so a key added later (or a recovered Google) starts working within the hour.
        Cache::put($key, $name ?? '', $name !== null ? now()->addDays(RideSetting::current()->geocode_cache_days) : now()->addHour());

        return $name;
    }

    /**
     * Jawad's fallback (TravelService::GetDistancePolyline): haversine per leg, times a road factor
     * (a straight line is shorter than the road), at the admin's average speed.
     *
     * @param  list<array{0: float, 1: float}>  $stops
     */
    public function estimate(float $originLat, float $originLng, float $destinationLat, float $destinationLng, array $stops = []): RouteResult
    {
        $settings = RideSetting::current();
        $factor = max(10000, $settings->fallback_road_factor_bp) / 10000;
        $metresPerSecond = max(1, $settings->fallback_speed_kmh) * 1000 / 3600;

        $points = [[$originLat, $originLng], ...array_map(fn (array $s) => [(float) $s[0], (float) $s[1]], $stops), [$destinationLat, $destinationLng]];
        $legs = [];
        for ($i = 0; $i < count($points) - 1; $i++) {
            $metres = (int) round(Geo::metres($points[$i][0], $points[$i][1], $points[$i + 1][0], $points[$i + 1][1]) * $factor);
            $legs[] = ['distance_m' => $metres, 'duration_s' => (int) round($metres / $metresPerSecond)];
        }

        return new RouteResult(
            distanceM: array_sum(array_column($legs, 'distance_m')),
            durationS: array_sum(array_column($legs, 'duration_s')),
            polyline: null,
            legs: $legs,
            estimated: true,
        );
    }
}
