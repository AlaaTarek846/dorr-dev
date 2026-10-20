<?php

namespace Modules\Rides\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Rides\Models\RideSetting;

/**
 * Mapbox, when the admin picks it instead of Google (O-02): the Directions API for the driving route —
 * the same encoded polyline (precision 5) the apps draw — and the Geocoding API for the city name. The
 * token is the admin's (Rides → Settings) or MAPBOX_TOKEN. Any failure gives null (the straight-line
 * estimate answers); a service that doesn't answer is left alone for a few minutes.
 */
class MapboxGeoProvider implements GeoProvider
{
    private const PAUSE = 'rides:mapbox-paused';

    public function route(float $originLat, float $originLng, float $destinationLat, float $destinationLng, array $stops = []): ?RouteResult
    {
        $token = RideSetting::current()->mapboxToken();
        if ($token === '' || Cache::has(self::PAUSE.':route')) {
            return null;
        }

        // Mapbox wants lng,lat pairs, origin → stops → destination.
        $coordinates = collect([[$originLat, $originLng], ...array_map(fn (array $s) => [(float) $s[0], (float) $s[1]], $stops), [$destinationLat, $destinationLng]])
            ->map(fn (array $p) => sprintf('%.6F,%.6F', $p[1], $p[0]))
            ->implode(';');

        try {
            $response = Http::timeout((int) config('rides.mapbox.timeout', 8))
                ->get(rtrim((string) config('rides.mapbox.directions_url'), '/').'/'.$coordinates, [
                    'geometries' => 'polyline', 'overview' => 'full', 'steps' => 'false', 'access_token' => $token,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('[Rides] Mapbox Directions unreachable: '.$e->getMessage());
            Cache::put(self::PAUSE.':route', 1, now()->addMinutes(5));

            return null;
        }

        $route = $response->successful() && $response->json('code') === 'Ok' ? $response->json('routes.0') : null;
        if (! is_array($route) || ! isset($route['distance'])) {
            Log::warning('[Rides] Mapbox gave no route', ['http' => $response->status(), 'code' => $response->json('code'), 'message' => $response->json('message')]);
            if ($response->serverError() || in_array($response->status(), [401, 403, 429], true)) {
                Cache::put(self::PAUSE.':route', 1, now()->addMinutes($response->status() === 429 ? 5 : 30));
            }

            return null;
        }

        return new RouteResult(
            distanceM: (int) round((float) $route['distance']),
            durationS: (int) round((float) ($route['duration'] ?? 0)),
            polyline: is_string($route['geometry'] ?? null) ? $route['geometry'] : null,
            legs: array_map(fn (array $leg) => [
                'distance_m' => (int) round((float) ($leg['distance'] ?? 0)),
                'duration_s' => (int) round((float) ($leg['duration'] ?? 0)),
            ], (array) ($route['legs'] ?? [])),
            estimated: false,
        );
    }

    public function cityName(float $lat, float $lng): ?string
    {
        $token = RideSetting::current()->mapboxToken();
        if ($token === '' || Cache::has(self::PAUSE.':geocode')) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('rides.mapbox.timeout', 8))
                ->get(rtrim((string) config('rides.mapbox.geocode_url'), '/').'/'.sprintf('%.6F,%.6F', $lng, $lat).'.json', [
                    'types' => 'place', 'language' => 'ar', 'limit' => 1, 'access_token' => $token,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('[Rides] Mapbox Geocoding unreachable: '.$e->getMessage());
            Cache::put(self::PAUSE.':geocode', 1, now()->addMinutes(5));

            return null;
        }

        if (! $response->successful()) {
            if ($response->serverError() || in_array($response->status(), [401, 403, 429], true)) {
                Cache::put(self::PAUSE.':geocode', 1, now()->addMinutes($response->status() === 429 ? 5 : 30));
            }

            return null;
        }
        $name = $response->json('features.0.text');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** A new token: try again at once. */
    public static function resume(): void
    {
        Cache::forget(self::PAUSE.':route');
        Cache::forget(self::PAUSE.':geocode');
    }
}
