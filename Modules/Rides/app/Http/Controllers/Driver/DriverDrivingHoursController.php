<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Services\Drivers\DrivingHours;

/** How much of the driving-time limit the captain has used (D-42, D-43); the app reloads it on `driver.changed`, never on a timer. */
class DriverDrivingHoursController extends Controller
{
    public function __invoke(Request $request, DrivingHours $hours): JsonResponse
    {
        /** @var Driver $driver */
        $driver = $request->user();

        return ApiResponse::success($hours->status($driver), __('api.retrieved'));
    }
}
