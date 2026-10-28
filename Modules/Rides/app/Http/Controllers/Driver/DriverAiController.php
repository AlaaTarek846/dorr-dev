<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Http\Resources\DriverRideResource;
use Modules\Rides\Models\Ride;
use Modules\Rides\Services\Ai\DriverAssistant;
use Modules\Rides\Services\Ai\RideAi;
use Modules\Rides\Services\Comfort\ComfortPreferences;
use Modules\Rides\Services\Dispatch\DemandInsights;
use Modules\Rides\Services\Features\RideFeatures;

/**
 * The captain's side of Block 06: where the work is (hot zones, the forecast), DORR Driver AI, and
 * answering the rider's «راحتي».
 */
class DriverAiController extends Controller
{
    /**
     * `GET demand?lat&lng` — hot zones now for everyone; the forecast (AI-02) and booked-ahead demand (AI-17)
     * on a DORR AI plan (locked with `ai.reason` otherwise).
     */
    public function demand(Request $request, DemandInsights $demand, RideAi $ai, RideFeatures $features): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $driver = $this->driver($request);
        [$lat, $lng] = [(float) $data['lat'], (float) $data['lng']];
        $aiOn = $driver->country_id !== null && $features->allows((int) $driver->country_id, $driver->city_id, 'driver_ai');
        $plan = $aiOn ? $ai->entitlement($driver) : ['allowed' => false, 'reason' => 'not_enabled'];

        return ApiResponse::success([
            'hot_zones' => $demand->hotZones($driver, $lat, $lng),
            'ai' => ['available' => $aiOn, 'allowed' => $plan['allowed'], 'reason' => $plan['allowed'] ? null : ($plan['reason'] ?? 'no_plan')],
            'forecast' => $plan['allowed'] ? $demand->forecast($driver, $lat, $lng) : null,
            'scheduled' => $plan['allowed'] ? $demand->scheduled($driver, $lat, $lng) : null,
        ], __('api.retrieved'));
    }

    /** `GET ai/assistant?lat&lng` — DORR Driver AI's cards. */
    public function assistant(Request $request, DriverAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return ApiResponse::success($assistant->cards($this->driver($request), isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null), __('api.retrieved'));
    }

    /** `POST ai/ask {question, lat?, lng?}` — ask DORR Driver AI about my work. */
    public function ask(Request $request, DriverAssistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:400'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return ApiResponse::success($assistant->ask($this->driver($request), $data['question'], isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null), __('api.retrieved'));
    }

    /** `POST rides/{uuid}/comfort/unable {item, reason}` — one of the rider's «راحتي» requests can't be done. */
    public function comfortUnable(Request $request, string $uuid, ComfortPreferences $comfort): JsonResponse
    {
        $data = $request->validate([
            'item' => ['required', 'string', 'max:40'],
            'reason' => ['required', Rule::in(ComfortPreferences::UNABLE_REASONS)],
        ]);
        $ride = Ride::query()->where('uuid', $uuid)->first() ?? throw new RidesException('ride_not_found', 404);
        $ride = $comfort->unable($this->driver($request), $ride, $data['item'], $data['reason']);

        return ApiResponse::success((new DriverRideResource($ride->loadMissing(['rider', 'stops'])))->resolve($request), __('rides.comfort.unable_sent'));
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver $driver */
        $driver = $request->user();

        return $driver;
    }
}
