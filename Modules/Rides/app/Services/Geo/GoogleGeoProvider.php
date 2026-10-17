<?php

namespace Modules\Rides\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Rides\Models\RideSetting;

/**
 * Google Maps Platform, server only. The key comes from the admin (RideSetting), else .env.
 * Routes API with a field mask so Google bills only what we read; Geocoding for the city name.
 * Any failure (no key, a refusal, no connection) gives null — ResilientGeoProvider takes over.
 *
 * When Google *refuses* (a wrong key, an API not switched on, billing, a used-up quota) or can't be
 * reached, that API is left alone for a while (`rides.google.pause_*`): every ride would otherwise wait
 * for a refusal first, and the free providers answer meanwhile. It is tried again when the time is up,
 * when the key is changed, or when the check in the admin passes.
 */
class GoogleGeoProvider implements GeoProvider
{
    public const ROUTES = 'routes';

    public const GEOCODING = 'geocoding';

    private const FIELD_MASK = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.legs.distanceMeters,routes.legs.duration';

    public function route(float $originLat, float $originLng, float $destinationLat, float $destinationLng, array $stops = []): ?RouteResult
    {
        $key = RideSetting::current()->googleKey();
        if ($key === '' || self::paused(self::ROUTES) !== null) {
            return null;
        }

        $point = fn (float $lat, float $lng) => ['location' => ['latLng' => ['latitude' => $lat, 'longitude' => $lng]]];

        try {
            $response = Http::timeout((int) config('rides.google.timeout', 10))
                ->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => self::FIELD_MASK])
                ->post((string) config('rides.google.routes_url'), [
                    'origin' => $point($originLat, $originLng),
                    'destination' => $point($destinationLat, $destinationLng),
                    'intermediates' => array_map(fn (array $s) => $point((float) $s[0], (float) $s[1]), $stops),
                    'travelMode' => 'DRIVE',
                    'routingPreference' => 'TRAFFIC_AWARE',
                    'languageCode' => 'ar',
                    'units' => 'METRIC',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('[Rides] Google Routes unreachable: '.$e->getMessage());
            self::pause(self::ROUTES, 'unreachable', (int) config('rides.google.pause_unreachable_min', 5));

            return null;
        }

        $route = $response->successful() ? $response->json('routes.0') : null;
        if (! is_array($route) || ! isset($route['distanceMeters'])) {
            $this->refused(self::ROUTES, $response->status(), $response->json('error.status'), $response->json('error.message'), $response->body());

            return null;
        }

        return new RouteResult(
            distanceM: (int) $route['distanceMeters'],
            durationS: self::seconds($route['duration'] ?? '0s'),
            polyline: $route['polyline']['encodedPolyline'] ?? null,
            legs: array_map(fn (array $leg) => [
                'distance_m' => (int) ($leg['distanceMeters'] ?? 0),
                'duration_s' => (int) self::seconds($leg['duration'] ?? '0s'),
            ], $route['legs'] ?? []),
        );
    }

    public function cityName(float $lat, float $lng): ?string
    {
        $key = RideSetting::current()->googleKey();
        if ($key === '' || self::paused(self::GEOCODING) !== null) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('rides.google.timeout', 10))->get((string) config('rides.google.geocode_url'), [
                'latlng' => $lat.','.$lng,
                'language' => 'ar',
                'result_type' => 'locality',
                'key' => $key,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('[Rides] Google Geocoding unreachable: '.$e->getMessage());
            self::pause(self::GEOCODING, 'unreachable', (int) config('rides.google.pause_unreachable_min', 5));

            return null;
        }

        $status = $response->json('status');
        if (! $response->successful() || $status !== 'OK') {
            // No result for a point is an answer, not a fault.
            if (! in_array($status, ['ZERO_RESULTS', null], true) || ! $response->successful()) {
                $this->refused(self::GEOCODING, $response->status(), is_string($status) ? $status : null, $response->json('error_message'), $response->body());
            }

            return null;
        }

        foreach ((array) $response->json('results') as $result) {
            foreach ((array) ($result['address_components'] ?? []) as $component) {
                if (in_array('locality', (array) ($component['types'] ?? []), true) && ($component['long_name'] ?? '') !== '') {
                    return (string) $component['long_name'];
                }
            }
        }

        return null;
    }

    /** Why this API is being left alone, or null when it is not (`reason`: a GoogleFailure kind or `unreachable`). */
    public static function paused(string $api): ?string
    {
        $reason = Cache::get(self::pauseKey($api));

        return is_string($reason) ? $reason : null;
    }

    /** Tries Google again right away (a new key, a passed check). */
    public static function resume(): void
    {
        Cache::forget(self::pauseKey(self::ROUTES));
        Cache::forget(self::pauseKey(self::GEOCODING));
    }

    private static function pause(string $api, string $reason, int $minutes): void
    {
        Cache::put(self::pauseKey($api), $reason, now()->addMinutes(max(1, $minutes)));
    }

    private static function pauseKey(string $api): string
    {
        return 'rides:google-paused:'.$api;
    }

    /** A refusal (or an unusable answer): remember it, log it once, and let the free providers answer. */
    private function refused(string $api, int $http, ?string $status, ?string $message, string $body): void
    {
        $kind = GoogleFailure::classify($http, $status, $message);

        if (GoogleFailure::isRefusal($kind)) {
            self::pause($api, $kind, (int) config('rides.google.pause_refused_min', 30));
        } elseif ($http >= 500) {
            self::pause($api, 'unreachable', (int) config('rides.google.pause_unreachable_min', 5));
        }

        Log::warning("[Rides] Google {$api} gave no usable answer", ['http' => $http, 'kind' => $kind, 'status' => $status, 'message' => $message, 'body' => mb_substr($body, 0, 300)]);
    }

    /** Google writes durations like "1234s". */
    private static function seconds(string $duration): int
    {
        return (int) round((float) rtrim($duration, 's'));
    }
}
