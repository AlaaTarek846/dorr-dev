<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Http\Resources\DriverRideResource;
use Modules\Rides\Models\RidePool;
use Modules\Rides\Services\Pool\RidePoolService;

/** «أنا معك» (O-30): where the captain is going, how far off his road he'll go, the riders in the car. */
class DriverPoolController extends Controller
{
    public function __construct(private readonly RidePoolService $pools) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($request), __('api.retrieved'));
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'destination' => ['required', 'array'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],
            'destination.address' => ['nullable', 'string', 'max:255'],
            'radius_m' => ['nullable', 'integer', 'min:100', 'max:100000'],
        ]);
        $this->pools->openRoute($this->driver($request), [
            'lat' => (float) $data['destination']['lat'], 'lng' => (float) $data['destination']['lng'], 'address' => $data['destination']['address'] ?? null,
        ], isset($data['radius_m']) ? (int) $data['radius_m'] : null);

        return ApiResponse::success($this->present($request), __('api.updated'));
    }

    public function close(Request $request): JsonResponse
    {
        $this->pools->close($this->driver($request));

        return ApiResponse::success($this->present($request), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present(Request $request): array
    {
        $driver = $this->driver($request);
        $pool = $this->pools->openFor($driver);

        return [
            // The corridor the captain may choose (the app hides the control when it can't change).
            'radius' => $this->pools->radiusLimits((int) $driver->country_id),
            'pool' => $pool === null ? null : [
                'uuid' => $pool->uuid,
                'mode' => $pool->mode,
                'destination' => ['lat' => $pool->destination_lat, 'lng' => $pool->destination_lng, 'address' => $pool->destination_address],
                'radius_m' => $pool->radius_m,
                'seats' => $pool->seats,
                'rides' => $pool->activeRides()->get()->map(fn ($ride) => (new DriverRideResource($ride))->toArray($request))->values(),
            ],
        ];
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
