<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Rides\Models\RideSetting;

/**
 * Which map the apps draw (O-01..O-03): the provider chosen in admin → Rides → Settings — Google (the
 * apps' own key) or Mapbox, with its public token and style. `ios_provider` (O-72) is what the iPhone app
 * draws with: the same provider, or `apple` (Apple Maps) when the admin chose it for iPhone.
 */
class MapConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $settings = RideSetting::current();
        $mapbox = $settings->mapProvider() === 'mapbox';

        return ApiResponse::success([
            'provider' => $settings->mapProvider(),
            'ios_provider' => $settings->iosMapEngine(),
            'mapbox_token' => $mapbox ? ($settings->mapboxToken() ?: null) : null,
            'mapbox_style' => $mapbox ? ($settings->mapbox_style ?: 'mapbox/streets-v12') : null,
        ], __('api.retrieved'));
    }
}
