<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Lifecycle\RideChangeService;
use Modules\User\Models\User;

/**
 * A new destination or one more stop during the ride (R-91..95, D-131..133). The rider previews the
 * effect, sends it or drops it (`mobile/v1/rides/{uuid}/changes…`); the captain accepts or declines
 * (`driver/v1/rides/{uuid}/changes/{change}/accept|reject`).
 */
class RideChangeController extends Controller
{
    public function preview(Request $request, string $uuid, RideChangeService $changes): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(['destination', 'add_stop'])],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        $change = $changes->preview($this->rider($request), $this->riderRide($request, $uuid), $data['type'] ?? 'destination', [
            'lat' => (float) $data['lat'], 'lng' => (float) $data['lng'], 'address' => $data['address'] ?? null,
        ]);

        return ApiResponse::success(RideChangeService::present($change, 'rider'), __('api.retrieved'), 201);
    }

    public function send(Request $request, string $uuid, string $change, RideChangeService $changes): JsonResponse
    {
        $result = $changes->send($this->rider($request), $this->riderRide($request, $uuid), $change);

        return ApiResponse::success(RideChangeService::present($result, 'rider'), __('rides.messages.change_sent'));
    }

    public function cancel(Request $request, string $uuid, string $change, RideChangeService $changes): JsonResponse
    {
        $result = $changes->cancel($this->rider($request), $this->riderRide($request, $uuid), $change);

        return ApiResponse::success(RideChangeService::present($result, 'rider'), __('api.updated'));
    }

    public function accept(Request $request, string $uuid, string $change, RideChangeService $changes): JsonResponse
    {
        return $this->answer($request, $uuid, $change, $changes, true);
    }

    public function reject(Request $request, string $uuid, string $change, RideChangeService $changes): JsonResponse
    {
        return $this->answer($request, $uuid, $change, $changes, false);
    }

    private function answer(Request $request, string $uuid, string $change, RideChangeService $changes, bool $accept): JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();
        $ride = Ride::query()->where('uuid', $uuid)->where('driver_id', $driver->id)->first() ?? throw new RidesException('ride_not_found', 404);
        $result = $changes->answer($driver, $ride, $change, $accept);

        return ApiResponse::success(RideChangeService::present($result, 'driver'), __('rides.messages.change_answered'));
    }

    private function riderRide(Request $request, string $uuid): Ride
    {
        return Ride::query()->where('uuid', $uuid)->where('user_id', $this->rider($request)->id)->first() ?? throw new RidesException('ride_not_found', 404);
    }

    private function rider(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
