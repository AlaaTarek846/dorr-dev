<?php

namespace Modules\Rides\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Http\Resources\RideResource;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Circle\FavoriteCircle;
use Modules\User\Models\User;

/** DORR Circle (R-INN-07): the favourite captains to choose from, and "search all captains" once asked. */
class CircleController extends Controller
{
    public function index(Request $request, FavoriteCircle $circle): JsonResponse
    {
        return ApiResponse::success(['drivers' => $circle->drivers($this->rider($request))], __('api.retrieved'));
    }

    public function widen(Request $request, string $uuid, FavoriteCircle $circle): JsonResponse
    {
        $ride = Ride::query()->where('uuid', $uuid)->where('user_id', $this->rider($request)->id)->first()
            ?? throw new RidesException('ride_not_found', 404);

        return ApiResponse::success(
            (new RideResource($circle->widen($this->rider($request), $ride)->loadMissing('driver')))->resolve($request),
            __('api.updated'),
        );
    }

    private function rider(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
