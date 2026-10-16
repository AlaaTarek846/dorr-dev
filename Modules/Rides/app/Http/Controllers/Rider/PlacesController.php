<?php

namespace Modules\Rides\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rides\Services\Places\SavedPlaces;
use Modules\User\Models\User;

/** R-5, R-174: Home, Work, favourite places and recent destinations, in one call for the "where to?" panel. */
class PlacesController extends Controller
{
    public function index(Request $request, SavedPlaces $places): JsonResponse
    {
        /** @var User $rider */
        $rider = $request->user();

        return ApiResponse::success($places->for($rider), __('api.retrieved'));
    }
}
