<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Dispatch\DispatchDiagnostics;

/** «Why didn't the request reach the captain?» (O-47) — for the captain, and for one ride in the admin. */
class DispatchCheckController extends Controller
{
    public function __construct(private readonly DispatchDiagnostics $diagnostics) {}

    public function driver(Request $request): JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();

        return ApiResponse::success($this->diagnostics->driver($driver), __('api.retrieved'));
    }

    public function ride(int $ride): JsonResponse
    {
        return ApiResponse::success($this->diagnostics->ride(Ride::query()->findOrFail($ride)), __('api.retrieved'));
    }
}
