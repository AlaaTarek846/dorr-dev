<?php

namespace Modules\Rides\Http\Resources;

use App\Models\Rating;
use Modules\Rides\Enums\RatingTarget;
use Modules\Rides\Services\Lifecycle\RideRatingService;
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
            // The cash to take: the total less what the rider's wallet already paid (O-37).
            'cash_to_collect_minor' => $this->payment_status === RidePaymentStatus::CashDue ? max(0, (int) $this->final_total_minor - (int) $this->snapshot('wallet_paid_minor', 0)) : null,
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
            // O-37: a cash ride paid partly from the wallet — that part (held, then paid), and the cash left.
            'wallet_part_minor' => $this->payment_method === \Modules\Rides\Enums\RidePaymentMethod::Cash
                ? ($this->status === RideStatus::Completed ? (int) $this->snapshot('wallet_paid_minor', 0) : app(\Modules\Rides\Services\Payment\RidePaymentService::class)->held($this->resource))
                : 0,
            // O-10: unread chat messages (the badge); O-09: the number to call from outside the app while the ride is on.
            'unread_messages' => $this->driver_id !== null ? \Modules\Rides\Services\Chat\RideChatService::unread($this->resource, 'driver') : 0,
            'call_phone' => $this->driver_id !== null ? \Modules\Rides\Services\Chat\RideChatService::callPhone($this->resource, 'driver') : null,
            // O-30..O-33: a With Me ride sharing the car — what sharing took off, and with how many.
            // O-17: booked by the hour — the hours and what each minute past them costs (counted from the start).
            'hourly' => ($h = $this->snapshot('hourly')) === null ? null : [
                'hours' => (int) $h['hours'], 'hour_price_minor' => (int) $h['hour_price_minor'],
                'overtime_per_min_minor' => (int) ($h['overtime_per_min_minor'] ?? 0),
                'ends_at' => $this->started_at?->copy()->addHours((int) $h['hours'])->toIso8601String(),
            ],
            // O-09: the in-app call ringing or going on now (null when none) — the app shows it on any reload.
            'call' => \Modules\Rides\Services\Chat\RideCallService::present($this->resource),
            'pool' => $this->pool_id === null ? null : [
                'discount_minor' => (int) $this->pool_discount_minor,
                'riders' => \Modules\Rides\Models\Ride::query()->where('pool_id', $this->pool_id)
                    ->whereIn('status', ['accepted', 'arrived', 'in_progress'])->count(),
                'share_with' => $this->share_with,
            ],
            'rated' => $this->status === RideStatus::Completed
                ? Rating::query()->where('unique_key', Rating::uniqueKeyFor($request->user(), $this->resource))->exists()
                : null,
            // O-41: what the captain scores the rider on.
            'rating_criteria' => $this->status === RideStatus::Completed ? RideRatingService::criteria($this->resource, RatingTarget::Rider) : [],
        ];
    }

    /** @return array{0: float, 1: float, 2: string}|null the pickup before the trip, then each stop left, then the destination */
    private function nextPoint(): ?array
    {
        return RideProgress::nextPoint($this->resource);
    }
}
