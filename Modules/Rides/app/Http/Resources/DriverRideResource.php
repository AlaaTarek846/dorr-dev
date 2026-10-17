<?php

namespace Modules\Rides\Http\Resources;

use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Rides\Enums\RidePaymentStatus;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RiderRideProfile;
use Modules\Rides\Services\Geo\RideProgress;
use Modules\Rides\Services\Lifecycle\RideChangeService;
use Modules\Rides\Services\Safety\SafetyCheckService;

/**
 * A ride as the driver sees it: the rider's first name and rating (never the phone — calls and chat
 * go through the app), what they earn, what to collect in cash, and a ready Google Maps link for the
 * next point — navigation opens outside the app (owner's decision, saves map costs).
 *
 * @mixin Ride
 */
class DriverRideResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = RiderRideProfile::query()->find($this->user_id);
        $next = $this->nextPoint();

        return [
            'uuid' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status->value,
            'version' => $this->version,
            ...RideResource::common($this->resource),
            'rider' => [
                'name' => strtok((string) $this->rider?->name, ' ') ?: null,
                'rating' => $profile !== null && $profile->rating_count > 0 ? (float) $profile->rating_avg : null,
                'trips_count' => (int) ($profile?->trips_count ?? 0),
            ],
            'needs_pin' => $this->pin !== null,
            'from_circle' => in_array((int) $request->user()?->getKey(), array_map('intval', (array) $this->circle_driver_ids), true),
            'earning_minor' => $this->driver_net_minor ?? $this->snapshot('breakdown.driver_net'),
            'cash_to_collect_minor' => $this->payment_status === RidePaymentStatus::CashDue ? $this->final_total_minor : null,
            'final_total_minor' => $this->final_total_minor,
            // D-182: the rider's tip, apart from the fare — all of it the captain's.
            'tip_minor' => $this->tip_minor,
            'lines' => RideResource::lines($this->resource, 'driver'),
            // "Are you OK?" waiting for the captain (D-144, D-396).
            'safety_check' => ($check = SafetyCheckService::current($this->resource, $request->user())) === null ? null : SafetyCheckService::present($check),
            // The rider asks for a new destination / stop: accept or decline it (D-131..133).
            'change_request' => ($change = RideChangeService::current($this->resource)) === null || $change->status !== 'pending' ? null : RideChangeService::present($change, 'driver'),
            // D-129: the time and distance left to the next point.
            'progress' => RideProgress::of($this->resource),
            'navigation' => $next === null ? null : [
                'lat' => $next[0],
                'lng' => $next[1],
                'google_maps_url' => 'https://www.google.com/maps/dir/?api=1&destination='.$next[0].','.$next[1].'&travelmode=driving',
            ],
            'rated' => $this->status === RideStatus::Completed
                ? Rating::query()->where('unique_key', Rating::uniqueKeyFor($request->user(), $this->resource))->exists()
                : null,
        ];
    }

    /** @return array{0: float, 1: float, 2: string}|null the pickup before the trip, then each stop left, then the destination */
    private function nextPoint(): ?array
    {
        return RideProgress::nextPoint($this->resource);
    }
}
