<?php

namespace Modules\Rides\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Rides\Models\RideSetting;

/**
 * "Does my Google key work?" — asks Google the two things this server uses it for (a route, a city
 * name) with one real call each and reports, per API, what Google really said and what to do about it.
 * Two billed calls, so it runs when someone asks (the admin's button, `rides:check-google`), never on a timer.
 * When both answer, Google is taken off its "left alone" pause at once.
 */
class GoogleKeyCheck
{
    /**
     * @return array{key_set: bool, source: ?string, hint: ?string, ok: bool, paused: array<string, ?string>, provider: string, checks: list<array<string, mixed>>}
     */
    public function run(): array
    {
        $settings = RideSetting::current();
        $key = $settings->googleKey();
        $source = $key === '' ? null : ($settings->google_maps_key ? 'admin' : 'env');

        $result = [
            'key_set' => $key !== '',
            'source' => $source,
            'hint' => $key !== '' ? '••••'.mb_substr($key, -4) : null,
            'ok' => false,
            'paused' => [
                GoogleGeoProvider::ROUTES => GoogleGeoProvider::paused(GoogleGeoProvider::ROUTES),
                GoogleGeoProvider::GEOCODING => GoogleGeoProvider::paused(GoogleGeoProvider::GEOCODING),
            ],
            'provider' => \Modules\Rides\Models\RideSetting::current()->mapProvider(),
            'checks' => [],
        ];

        if ($key === '') {
            $result['checks'][] = $this->row(GoogleGeoProvider::ROUTES, false, null, null, 'key_missing', null);
            $result['checks'][] = $this->row(GoogleGeoProvider::GEOCODING, false, null, null, 'key_missing', null);

            return $result;
        }

        $result['checks'][] = $this->routes($key);
        $result['checks'][] = $this->geocoding($key);
        $result['ok'] = collect($result['checks'])->every(fn (array $c) => $c['ok']);

        if ($result['ok']) {
            GoogleGeoProvider::resume();
            $result['paused'] = [GoogleGeoProvider::ROUTES => null, GoogleGeoProvider::GEOCODING => null];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function routes(string $key): array
    {
        $point = fn (float $lat, float $lng) => ['location' => ['latLng' => ['latitude' => $lat, 'longitude' => $lng]]];

        try {
            $response = Http::timeout((int) config('rides.google.timeout', 10))
                ->withHeaders(['X-Goog-Api-Key' => $key, 'X-Goog-FieldMask' => 'routes.distanceMeters'])
                ->post((string) config('rides.google.routes_url'), [
                    'origin' => $point(24.7136, 46.6753),
                    'destination' => $point(24.7236, 46.6853),
                    'travelMode' => 'DRIVE',
                ]);
        } catch (ConnectionException $e) {
            return $this->row(GoogleGeoProvider::ROUTES, false, null, null, 'unreachable', $e->getMessage());
        }

        if ($response->successful() && isset($response->json('routes.0')['distanceMeters'])) {
            return $this->row(GoogleGeoProvider::ROUTES, true, $response->status(), 'OK', 'ok', null);
        }

        $status = $response->json('error.status');
        $message = $response->json('error.message');

        return $this->row(GoogleGeoProvider::ROUTES, false, $response->status(), is_string($status) ? $status : null, GoogleFailure::classify($response->status(), is_string($status) ? $status : null, is_string($message) ? $message : null), is_string($message) ? $message : mb_substr($response->body(), 0, 200));
    }

    /** @return array<string, mixed> */
    private function geocoding(string $key): array
    {
        try {
            $response = Http::timeout((int) config('rides.google.timeout', 10))->get((string) config('rides.google.geocode_url'), [
                'latlng' => '24.7136,46.6753', 'language' => 'en', 'result_type' => 'locality', 'key' => $key,
            ]);
        } catch (ConnectionException $e) {
            return $this->row(GoogleGeoProvider::GEOCODING, false, null, null, 'unreachable', $e->getMessage());
        }

        $status = $response->json('status');
        if ($response->successful() && in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            return $this->row(GoogleGeoProvider::GEOCODING, true, $response->status(), $status, 'ok', null);
        }

        $message = $response->json('error_message');

        return $this->row(GoogleGeoProvider::GEOCODING, false, $response->status(), is_string($status) ? $status : null, GoogleFailure::classify($response->status(), is_string($status) ? $status : null, is_string($message) ? $message : null), is_string($message) ? $message : mb_substr($response->body(), 0, 200));
    }

    /** @return array<string, mixed> */
    private function row(string $api, bool $ok, ?int $http, ?string $status, string $kind, ?string $message): array
    {
        return [
            'api' => $api,
            'ok' => $ok,
            'http' => $http,
            'status' => $status,
            'kind' => $kind,
            'message' => $message,
            'hint' => __('rides.google_check.'.$kind),
        ];
    }
}
