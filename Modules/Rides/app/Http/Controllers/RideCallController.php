<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCall;
use Modules\Rides\Services\Chat\RideCallService;

/** In-app voice calls on a ride (O-09), for the rider (`mobile/v1/rides/…`) and the captain (`driver/v1/rides/…`). */
class RideCallController extends Controller
{
    public function __construct(private readonly RideCallService $calls) {}

    public function start(Request $request, string $uuid): JsonResponse
    {
        ['call' => $call, 'join' => $join] = $this->calls->start($this->ride($request, $uuid), $this->side($request));

        return ApiResponse::success($this->present($call) + ['join' => $join], __('api.created'), 201);
    }

    public function accept(Request $request, string $uuid, string $call): JsonResponse
    {
        $ride = $this->ride($request, $uuid);
        ['call' => $row, 'join' => $join] = $this->calls->accept($ride, $this->call($ride, $call), $this->side($request));

        return ApiResponse::success($this->present($row) + ['join' => $join], __('api.updated'));
    }

    /** Decline a ring, cancel my own, or hang up. */
    public function end(Request $request, string $uuid, string $call): JsonResponse
    {
        $ride = $this->ride($request, $uuid);

        return ApiResponse::success($this->present($this->calls->end($ride, $this->call($ride, $call), $this->side($request))), __('api.updated'));
    }

    private function side(Request $request): string
    {
        return $request->user() instanceof Driver ? 'driver' : 'user';
    }

    private function ride(Request $request, string $uuid): Ride
    {
        $me = $request->user();

        return Ride::query()->where('uuid', $uuid)->where($me instanceof Driver ? 'driver_id' : 'user_id', $me->getKey())->first()
            ?? throw new RidesException('ride_not_found', 404);
    }

    private function call(Ride $ride, string $uuid): RideCall
    {
        return RideCall::query()->where('ride_id', $ride->id)->where('uuid', $uuid)->first() ?? throw new RidesException('call_over', 404);
    }

    /** @return array<string, mixed> */
    private function present(RideCall $call): array
    {
        return [
            'uuid' => $call->uuid, 'status' => $call->status, 'caller' => $call->caller,
            'started_at' => $call->created_at?->toIso8601String(), 'answered_at' => $call->answered_at?->toIso8601String(),
        ];
    }
}
