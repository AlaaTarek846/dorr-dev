<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Rides\Models\RideSetting;

/**
 * Which map the apps draw (O-01..O-03): the provider chosen in admin → Rides → Settings — Google (the
 * apps' own Android key) or Mapbox, with its public token and style. Read once when a map screen opens.
 */
class MapConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $settings = RideSetting::current();
        $mapbox = $settings->mapProvider() === 'mapbox';

        return ApiResponse::success([
            'provider' => $settings->mapProvider(),
            'mapbox_token' => $mapbox ? ($settings->mapboxToken() ?: null) : null,
            'mapbox_style' => $mapbox ? ($settings->mapbox_style ?: 'mapbox/streets-v12') : null,
        ], __('api.retrieved'));
    }
}
