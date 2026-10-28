<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RidePaymentMethod;
use Modules\Rides\Services\Drivers\DriverPreferences;

/**
 * The captain's driving preferences (D-32..D-37): the categories they want, how far they go for a
 * pickup, and Auto-Accept with its filters. Saving sends back the whole screen's state.
 */
class DriverPreferencesController extends Controller
{
    public function show(Request $request, DriverPreferences $preferences): JsonResponse
    {
        return ApiResponse::success($preferences->show($this->driver($request)), __('api.retrieved'));
    }

    public function update(Request $request, DriverPreferences $preferences): JsonResponse
    {
        $data = $request->validate([
            'categories' => ['sometimes', 'nullable', 'array'],
            'categories.*' => ['string', 'max:64'],
            'pickup_radius_m' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'pet_friendly' => ['sometimes', 'boolean'],
            'child_seats' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'next_trips' => ['sometimes', 'boolean'],
            'daily_goal_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'auto_accept' => ['sometimes', 'array'],
            'auto_accept.enabled' => ['sometimes', 'boolean'],
            'auto_accept.max_pickup_m' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'auto_accept.min_fare_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'auto_accept.min_per_km_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'auto_accept.payment_methods' => ['sometimes', 'nullable', 'array'],
            'auto_accept.payment_methods.*' => [Rule::enum(RidePaymentMethod::class)],
        ]);

        return ApiResponse::success($preferences->save($this->driver($request), $data), __('api.updated'));
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
