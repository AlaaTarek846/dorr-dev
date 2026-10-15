<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Http\Resources\DriverRideResource;
use Modules\Rides\Http\Resources\RideResource;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RiderRideProfile;
use Modules\Rides\Services\Scheduling\ScheduledRideService;

/**
 * Scheduled rides for the captain (D-260..269): the upcoming bookings near them, reserving one, their
 * own reservations, giving one back (they see first whether it counts as a cancel), and heading to
 * the pickup when the time comes.
 */
class DriverScheduledController extends Controller
{
    public function available(Request $request, ScheduledRideService $scheduled): JsonResponse
    {
        return ApiResponse::success($scheduled->available($this->driver($request))->map(fn (Ride $r) => $this->card($r))->values(), __('api.retrieved'));
    }

    public function mine(Request $request, ScheduledRideService $scheduled): JsonResponse
    {
        return ApiResponse::success($scheduled->mine($this->driver($request))->map(fn (Ride $r) => $this->card($r, true))->values(), __('api.retrieved'));
    }

    public function reserve(Request $request, string $uuid, ScheduledRideService $scheduled): JsonResponse
    {
        $ride = $scheduled->reserve($this->driver($request), $uuid, $request->header('Idempotency-Key'));

        return ApiResponse::success($this->card($ride->loadMissing(['category.translations', 'currency', 'stops']), true), __('rides.messages.reserved'));
    }

    public function release(Request $request, string $uuid, ScheduledRideService $scheduled): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $scheduled->release($this->driver($request), $uuid, $data['note'] ?? null);

        return ApiResponse::success(null, __('rides.messages.reservation_released'));
    }

    public function start(Request $request, string $uuid, ScheduledRideService $scheduled): JsonResponse
    {
        $ride = $scheduled->startHeading($this->driver($request), $uuid, $request->header('Idempotency-Key'));

        return ApiResponse::success((new DriverRideResource($ride->loadMissing(['rider', 'stops'])))->resolve($request), __('rides.messages.accepted'));
    }

    /**
     * One booking as the list shows it: when, where, the fixed net (D-261), the time to be online
     * (D-263) and — for my own — whether I may head out now and what giving it back would cost.
     *
     * @return array<string, mixed>
     */
    private function card(Ride $ride, bool $mine = false): array
    {
        $profile = RiderRideProfile::query()->find($ride->user_id);
        $startsAt = ScheduledRideService::startsAt($ride);

        return [
            'uuid' => $ride->uuid,
            'number' => $ride->number,
            'scheduled_at' => $ride->scheduled_at?->toIso8601String(),
            'online_from' => $ride->scheduled_at?->copy()->subSeconds((int) $ride->policy('scheduled.lead_time_s', 1800))->toIso8601String(),
            'earning_minor' => $ride->snapshot('breakdown.driver_net'),
            'pickup_distance_m' => $ride->getAttribute('pickup_distance_m'),
            'rider_rating' => $profile !== null && $profile->rating_count > 0 ? (float) $profile->rating_avg : null,
            'mine' => $mine,
            'can_start' => $mine && ($startsAt === null || ! $startsAt->isFuture()),
            'starts_at' => $mine ? $startsAt?->toIso8601String() : null,
            'release_counts_as_cancel' => $mine ? ScheduledRideService::releaseCounts($ride) : null,
            ...RideResource::common($ride),
        ];
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
