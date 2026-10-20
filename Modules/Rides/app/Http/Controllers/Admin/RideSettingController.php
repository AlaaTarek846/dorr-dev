<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Rides\Models\RideSetting;
use Modules\Rides\Services\Geo\GoogleGeoProvider;
use Modules\Rides\Services\Geo\GoogleKeyCheck;
use Modules\Rides\Services\Geo\ResilientGeoProvider;

/**
 * Admin → Rides → Settings: the Google Maps server key (never shown back — only whether it is set
 * and its last 4 characters), a test of it, and the free estimate used when neither Google nor the
 * free map can answer.
 */
class RideSettingController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('ride-settings', [
            ['view', ['show']],
            ['update', ['update', 'checkGoogle']],
        ]);
    }

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->present(RideSetting::current()), __('api.retrieved'));
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            // Empty string clears the key (Google is then skipped and the free estimate used).
            'google_maps_key' => ['sometimes', 'nullable', 'string', 'max:200'],
            // O-01, O-02: Google or Mapbox — one of them — and Mapbox's public token and style.
            'map_provider' => ['sometimes', 'string', 'in:'.implode(',', RideSetting::PROVIDERS)],
            'mapbox_token' => ['sometimes', 'nullable', 'string', 'max:400'],
            'mapbox_style' => ['sometimes', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/'],
            'fallback_speed_kmh' => ['sometimes', 'integer', 'between:10,150'],
            'fallback_road_factor_bp' => ['sometimes', 'integer', 'between:10000,30000'],
            'geocode_cache_days' => ['sometimes', 'integer', 'between:1,365'],
        ]);

        $settings = RideSetting::current();
        $before = $this->present($settings);

        if (array_key_exists('google_maps_key', $data)) {
            $data['google_maps_key'] = trim((string) $data['google_maps_key']) ?: null;
        }
        if (array_key_exists('mapbox_token', $data)) {
            $data['mapbox_token'] = trim((string) $data['mapbox_token']) ?: null;
        }
        $settings->fill($data)->save();

        // A new key: forget the "no city name" misses and the pause after a refusal, so Google is asked again right away.
        if (array_key_exists('google_maps_key', $data) || array_key_exists('mapbox_token', $data) || array_key_exists('map_provider', $data)) {
            ResilientGeoProvider::forgetCityNames();
            GoogleGeoProvider::resume();
            \Modules\Rides\Services\Geo\MapboxGeoProvider::resume();
        }

        $after = $this->present($settings->fresh());
        $audit->record('ride_settings.updated', $settings, $before, $after);

        return ApiResponse::success($after, __('api.updated'));
    }

    /** "Does my key work?" — one real call to each Google API in use, and what Google said (two billed calls). */
    public function checkGoogle(GoogleKeyCheck $check): JsonResponse
    {
        return ApiResponse::success($check->run(), __('api.retrieved'));
    }

    /** @return array<string, mixed> */
    private function present(RideSetting $settings): array
    {
        $key = (string) $settings->google_maps_key;
        $mapbox = (string) $settings->mapbox_token;

        return [
            'map_provider' => $settings->mapProvider(),
            'mapbox_token_set' => $mapbox !== '',
            'mapbox_token_hint' => $mapbox !== '' ? '••••'.mb_substr($mapbox, -4) : null,
            'mapbox_env_set' => (string) config('rides.mapbox.token') !== '',
            'mapbox_style' => $settings->mapbox_style ?: 'mapbox/streets-v12',
            'google_maps_key_set' => $key !== '',
            'google_maps_key_hint' => $key !== '' ? '••••'.mb_substr($key, -4) : null,
            'env_key_set' => (string) config('rides.google.key') !== '',
            'google_paused' => [
                'routes' => GoogleGeoProvider::paused(GoogleGeoProvider::ROUTES),
                'geocoding' => GoogleGeoProvider::paused(GoogleGeoProvider::GEOCODING),
            ],
            'fallback_speed_kmh' => $settings->fallback_speed_kmh,
            'fallback_road_factor_bp' => $settings->fallback_road_factor_bp,
            'geocode_cache_days' => $settings->geocode_cache_days,
        ];
    }
}
