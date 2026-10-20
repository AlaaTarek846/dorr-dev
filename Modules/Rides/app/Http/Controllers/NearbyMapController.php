<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rides\Services\Dispatch\NearbyMap;

/** The maps' surroundings: the free cars around a rider (O-59), the hot areas around a captain (O-58). */
class NearbyMapController extends Controller
{
    public function __construct(private readonly NearbyMap $map) {}

    public function cars(Request $request): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);

        return ApiResponse::success($this->map->cars((float) $data['lat'], (float) $data['lng']), __('api.retrieved'));
    }

    public function heat(Request $request): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric', 'between:-90,90'], 'lng' => ['required', 'numeric', 'between:-180,180']]);

        return ApiResponse::success($this->map->heat((float) $data['lat'], (float) $data['lng']), __('api.retrieved'));
    }
}
