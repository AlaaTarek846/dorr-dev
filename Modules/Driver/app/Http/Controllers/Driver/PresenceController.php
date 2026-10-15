<?php

namespace Modules\Driver\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Contracts\DriverWorkGate;
use Modules\Driver\Models\Driver;
use Modules\Driver\Models\DriverPresence;
use Modules\Driver\Services\LocationIngestService;
use Modules\Driver\Services\PresenceService;

/**
 * Online / offline / pause / last ride, and the location batches (docs/rides-plan.md §10, §17).
 */
class PresenceController extends Controller
{
    public function __construct(
        private readonly PresenceService $presence,
        private readonly DriverWorkGate $gate,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        return ApiResponse::success($this->present($this->presence->of($driver), $driver), __('api.retrieved'));
    }

    public function online(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);
        $driver = $this->driver($request);

        $presence = $this->presence->goOnline($driver, (float) $data['lat'], (float) $data['lng'], $data['app_version'] ?? null);

        return ApiResponse::success($this->present($presence, $driver), __('api.updated'));
    }

    public function offline(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        return ApiResponse::success($this->present($this->presence->goOffline($driver), $driver), __('api.updated'));
    }

    public function pause(Request $request): JsonResponse
    {
        $data = $request->validate(['minutes' => ['required', 'integer', 'between:1,120']]);
        $driver = $this->driver($request);

        return ApiResponse::success($this->present($this->presence->pause($driver, (int) $data['minutes']), $driver), __('api.updated'));
    }

    public function lastRide(Request $request): JsonResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $driver = $this->driver($request);

        return ApiResponse::success($this->present($this->presence->setLastRide($driver, (bool) $data['on']), $driver), __('api.updated'));
    }

    public function locations(Request $request, LocationIngestService $ingest): JsonResponse
    {
        $data = $request->validate([
            'batch_uuid' => ['required', 'uuid'],
            'clock_offset_ms' => ['nullable', 'integer', 'between:-86400000,86400000'],
            'points' => ['required', 'array', 'min:1', 'max:500'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy_m' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'points.*.speed_mps' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'points.*.heading' => ['nullable', 'integer', 'between:0,359'],
            'points.*.recorded_at' => ['required', 'date'],
            'points.*.device_time' => ['nullable', 'date'],
            'points.*.is_mock' => ['nullable', 'boolean'],
        ]);

        $result = $ingest->ingest($this->driver($request), $data['batch_uuid'], $data['points'], (int) ($data['clock_offset_ms'] ?? 0));

        return ApiResponse::success($result, __('api.updated'));
    }

    public function gps(Request $request, LocationIngestService $ingest): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $ingest->gpsChanged($this->driver($request), (bool) $data['enabled']);

        return ApiResponse::success([], __('api.updated'));
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user('driver_api');
    }

    /** @return array<string, mixed> */
    private function present(DriverPresence $presence, Driver $driver): array
    {
        $ride = $this->gate->activeRide($driver);

        return [
            'status' => $presence->status->value,
            'online_since' => $presence->online_since?->toIso8601String(),
            'paused_until' => $presence->paused_until?->toIso8601String(),
            'last_ride_requested' => $presence->last_ride_requested,
            'gps_enabled' => $presence->gps_enabled,
            'can_go_online' => $this->gate->reasonNotToWork($driver) === null,
            'reason' => $this->gate->reasonNotToWork($driver),
            'active_ride_id' => $ride['id'] ?? null,
        ];
    }
}
