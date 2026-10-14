<?php

namespace Modules\Rides\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Rides\Models\RideSetting;

/**
 * Google Maps Platform, server only. The key comes from the admin (RideSetting), else .env.
 * Routes API with a field mask so Google bills only what we read; Geocoding for the city name.
 * Any failure (no key, a refusal, no connection) gives null — ResilientGeoProvider takes over.
 */
class GoogleGeoProvider implements GeoProvider
{
    private const FIELD_MASK = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline,routes.legs.distanceMeters,routes.legs.duration';

    private const GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function route(float $originLat, float $originLng, float $destinationLat, float $destinationLng, array $stops = []): ?RouteResult
    {
        $key = RideSetting::current()->googleKey();
        if ($key === '') {
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

            return null;
        }

        $route = $response->successful() ? $response->json('routes.0') : null;
        if (! is_array($route) || ! isset($route['distanceMeters'])) {
            Log::warning('[Rides] Google Routes gave no route', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            return null;
        }

        return new RouteResult(
            distanceM: (int) $route['distanceMeters'],
            durationS: self::seconds($route['duration'] ?? '0s'),
            polyline: $route['polyline']['encodedPolyline'] ?? null,
            legs: array_map(fn (array $leg) => [
                'distance_m' => (int) ($leg['distanceMeters'] ?? 0),
                'duration_s' => self::seconds($leg['duration'] ?? '0s'),
            ], $route['legs'] ?? []),
        );
    }

    public function cityName(float $lat, float $lng): ?string
    {
        $key = RideSetting::current()->googleKey();
        if ($key === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('rides.google.timeout', 10))->get(self::GEOCODE_URL, [
                'latlng' => $lat.','.$lng,
                'language' => 'ar',
                'result_type' => 'locality',
                'key' => $key,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('[Rides] Google Geocoding unreachable: '.$e->getMessage());

            return null;
        }

        if (! $response->successful() || $response->json('status') !== 'OK') {
            if (! in_array($response->json('status'), ['ZERO_RESULTS', null], true)) {
                Log::warning('[Rides] Google Geocoding refused', ['status' => $response->json('status'), 'error' => $response->json('error_message')]);
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

    /** Google writes durations like "1234s". */
    private static function seconds(string $duration): int
    {
        return (int) round((float) rtrim($duration, 's'));
    }
}
