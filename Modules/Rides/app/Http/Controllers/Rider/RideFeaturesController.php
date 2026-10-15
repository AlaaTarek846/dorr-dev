<?php

namespace Modules\Rides\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rides\Http\Middleware\RideLocation;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;

/**
 * `GET rides/features?lat=&lng=` — what the rider app may show here (docs/rides-plan.md §5): the
 * service itself, each category and each feature. Only the city is used, never stored.
 */
class RideFeaturesController extends Controller
{
    public function index(Request $request, RideFeatures $features, CityResolver $cities): JsonResponse
    {
        $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        [$countryId, $cityId] = RideLocation::of($request, $cities);

        $snapshot = $countryId === null
            ? ['service' => false, 'categories' => [], 'features' => array_fill_keys(RideFeatures::FEATURES, false)]
            : $features->snapshot($countryId, $cityId);

        return ApiResponse::success([
            'country_id' => $countryId,
            'city_id' => $cityId,
            ...$snapshot,
        ], __('api.retrieved'));
    }
}
