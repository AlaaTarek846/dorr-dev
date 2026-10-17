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
            'fallback_speed_kmh' => ['sometimes', 'integer', 'between:10,150'],
            'fallback_road_factor_bp' => ['sometimes', 'integer', 'between:10000,30000'],
            'geocode_cache_days' => ['sometimes', 'integer', 'between:1,365'],
        ]);

        $settings = RideSetting::current();
        $before = $this->present($settings);

        if (array_key_exists('google_maps_key', $data)) {
            $data['google_maps_key'] = trim((string) $data['google_maps_key']) ?: null;
        }
        $settings->fill($data)->save();

        // A new key: forget the "no city name" misses and the pause after a refusal, so Google is asked again right away.
        if (array_key_exists('google_maps_key', $data)) {
            ResilientGeoProvider::forgetCityNames();
            GoogleGeoProvider::resume();
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

        return [
            'google_maps_key_set' => $key !== '',
            'google_maps_key_hint' => $key !== '' ? '••••'.mb_substr($key, -4) : null,
            'env_key_set' => (string) config('rides.google.key') !== '',
            'osm_fallback' => (bool) config('rides.osm.enabled', true),
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
