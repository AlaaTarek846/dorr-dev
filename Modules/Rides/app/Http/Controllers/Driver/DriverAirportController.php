<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Services\Airport\AirportQueue;
use Modules\Rides\Services\Airport\RestrictedZones;

/**
 * The captain's Airport screen (D-276..D-283): their place in the airport queue, joining and leaving
 * it, the official pickup points, and the areas where pickups aren't allowed. Live: the app reloads on
 * `driver.changed`, it never asks on a timer.
 */
class DriverAirportController extends Controller
{
    public function show(Request $request, AirportQueue $queue): JsonResponse
    {
        return ApiResponse::success($queue->status($this->driver($request)), __('api.retrieved'));
    }

    public function join(Request $request, AirportQueue $queue): JsonResponse
    {
        $driver = $this->driver($request);
        $queue->join($driver, 'manual');

        return ApiResponse::success($queue->status($driver), __('rides.messages.queue_joined'));
    }

    public function leave(Request $request, AirportQueue $queue): JsonResponse
    {
        $driver = $this->driver($request);
        $queue->leave($driver, 'manual');

        return ApiResponse::success($queue->status($driver), __('rides.messages.queue_left'));
    }

    public function restrictedZones(Request $request, RestrictedZones $zones): JsonResponse
    {
        return ApiResponse::success($zones->listFor($this->driver($request)), __('api.retrieved'));
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
