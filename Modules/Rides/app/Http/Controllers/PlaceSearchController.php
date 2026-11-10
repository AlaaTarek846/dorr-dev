<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rides\Services\Geo\PlaceSearch;

/** Finding a place (O-57): suggestions as you type, and the popular places nearby before you do. Rider and captain. */
class PlaceSearchController extends Controller
{
    public function __construct(private readonly PlaceSearch $places) {}

    public function suggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:120'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return ApiResponse::success($this->places->suggest($data['q'], isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null, app()->getLocale()), __('api.retrieved'));
    }

    public function popular(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return ApiResponse::success($this->places->popular((float) $data['lat'], (float) $data['lng'], app()->getLocale()), __('api.retrieved'));
    }
}
