<?php

namespace Modules\Rides\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The free map (OpenStreetMap data), used automatically while Google can't answer: OSRM for the driving
 * route — a real road route with the same encoded polyline the apps already draw — and Nominatim for the
 * city name. Nothing is billed, and nothing needs a key.
 *
 * The public servers are for light use (Nominatim: one request a second, an identifying User-Agent;
 * OSRM's demo server: no heavy traffic) — `rides.osm.*` points at your own when the traffic grows.
 * Any failure gives null; a server that doesn't answer is left alone for a few minutes.
 */
class OsmGeoProvider implements GeoProvider
{
    private const PAUSE = 'rides:osm-paused';

    public function route(float $originLat, float $originLng, float $destinationLat, float $destinationLng, array $stops = []): ?RouteResult
    {
        if (! $this->enabled() || Cache::has(self::PAUSE.':route')) {
            return null;
        }

        // OSRM wants lng,lat pairs, origin → stops → destination.
        $coordinates = collect([[$originLat, $originLng], ...array_map(fn (array $s) => [(float) $s[0], (float) $s[1]], $stops), [$destinationLat, $destinationLng]])
            ->map(fn (array $p) => sprintf('%.6F,%.6F', $p[1], $p[0]))
            ->implode(';');

        try {
            $response = Http::timeout((int) config('rides.osm.timeout', 6))
                ->withHeaders(['User-Agent' => (string) config('rides.osm.user_agent')])
                ->get(config('rides.osm.routing_url').'/route/v1/driving/'.$coordinates, ['overview' => 'full', 'geometries' => 'polyline', 'steps' => 'false']);
        } catch (ConnectionException $e) {
            Log::warning('[Rides] OSRM unreachable: '.$e->getMessage());
            Cache::put(self::PAUSE.':route', 1, now()->addMinutes(5));

            return null;
        }

        $route = $response->successful() && $response->json('code') === 'Ok' ? $response->json('routes.0') : null;
        if (! is_array($route) || ! isset($route['distance'])) {
            Log::warning('[Rides] OSRM gave no route', ['http' => $response->status(), 'code' => $response->json('code'), 'body' => mb_substr($response->body(), 0, 200)]);
            if ($response->serverError() || $response->status() === 429) {
                Cache::put(self::PAUSE.':route', 1, now()->addMinutes(5));
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
            ], $route['legs'] ?? []),
        );
    }

    public function cityName(float $lat, float $lng): ?string
    {
        if (! $this->enabled() || Cache::has(self::PAUSE.':geocode')) {
            return null;
        }

        // Nominatim's policy: at most one request a second. Over that, answer "unknown" rather than wait.
        if (! RateLimiter::attempt('rides:nominatim', 1, fn () => true, 1)) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('rides.osm.timeout', 6))
                ->withHeaders(['User-Agent' => (string) config('rides.osm.user_agent')])
                ->get(config('rides.osm.geocode_url').'/reverse', [
                    'format' => 'jsonv2', 'lat' => $lat, 'lon' => $lng, 'zoom' => 10, 'addressdetails' => 1, 'accept-language' => 'ar',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('[Rides] Nominatim unreachable: '.$e->getMessage());
            Cache::put(self::PAUSE.':geocode', 1, now()->addMinutes(5));

            return null;
        }

        if (! $response->successful()) {
            if ($response->serverError() || $response->status() === 429) {
                Cache::put(self::PAUSE.':geocode', 1, now()->addMinutes(5));
            }

            return null;
        }

        $address = (array) $response->json('address');
        foreach (['city', 'town', 'village', 'municipality', 'county'] as $part) {
            if (is_string($address[$part] ?? null) && $address[$part] !== '') {
                return $address[$part];
            }
        }

        return null;
    }

    private function enabled(): bool
    {
        return (bool) config('rides.osm.enabled', true);
    }
}
