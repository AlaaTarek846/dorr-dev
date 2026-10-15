<?php

namespace Modules\Rides\Services\Regulator\Wasl;

use Illuminate\Database\Eloquent\Collection;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Enums\WaslTripStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideRegulatorCity;
use Modules\Rides\Models\RiderRideProfile;
use Modules\Rides\Services\Regulator\RegulatorCityNames;

/**
 * A completed ride registered at Wasl (`/dispatching/v2/trips`, docs/rides-plan.md §11.2). Never
 * holds up the rider or the driver: `rides:wasl-trips` sends what's due every minute and retries a
 * failure on Jawad's schedule (1, 5, 10, 30, 60 minutes); codes that can't succeed stop at once.
 * The city names are only ever the stored official ones — a place without one waits in the admin.
 */
class WaslTripService
{
    public const CITY_UNMAPPED = 'CITY_UNMAPPED';

    public function __construct(
        private readonly WaslClient $client,
        private readonly RegulatorCityNames $cityNames,
    ) {}

    /** @return Collection<int, Ride> rides whose next try is due */
    public function due(): Collection
    {
        $backoff = (array) config('rides.wasl.trip_backoff_s');

        return Ride::query()
            ->where('status', RideStatus::Completed->value)
            ->where('wasl_status', WaslTripStatus::Pending->value)
            ->where('wasl_attempts', '<', count($backoff))
            ->orderBy('id')->limit(200)->get()
            ->filter(fn (Ride $r) => $r->wasl_attempts === 0 || $r->wasl_last_attempt_at === null
                || $r->wasl_last_attempt_at->copy()->addSeconds((int) ($backoff[$r->wasl_attempts - 1] ?? 3600))->isPast())
            ->values();
    }

    public function send(Ride $ride): Ride
    {
        if (! $this->client->configured() || ! in_array($ride->wasl_status, [WaslTripStatus::Pending, WaslTripStatus::Failed], true)) {
            return $ride;
        }
        $ride->loadMissing(['driver', 'vehicle']);

        $origin = $this->cityNames->officialName(RideRegulatorCity::WASL, $ride->origin_lat, $ride->origin_lng);
        $destination = $this->cityNames->officialName(RideRegulatorCity::WASL, (float) $ride->destination_lat, (float) $ride->destination_lng);
        if ($origin === null || $destination === null) {
            // Nothing is guessed: the admin maps the city, then "send again".
            $ride->forceFill(['wasl_result_code' => self::CITY_UNMAPPED, 'wasl_last_attempt_at' => now()])->save();

            return $ride;
        }

        $ride->forceFill(['wasl_origin_city' => $origin, 'wasl_destination_city' => $destination]);
        $result = $this->client->post('/dispatching/v2/trips', $this->body($ride, $origin, $destination), 'trip', 'ride', $ride->id);
        $attempts = $ride->wasl_attempts + 1;
        $code = WaslClient::resultCode($result);

        if (WaslClient::succeeded($result)) {
            $ride->forceFill(['wasl_status' => WaslTripStatus::Sent, 'wasl_sent_at' => now(), 'wasl_result_code' => 'success', 'wasl_result_message' => null]);
            // An accepted name is locked from now on (docs/rides-structure.md §7.2).
            RideRegulatorCity::query()->where('regulator', RideRegulatorCity::WASL)->whereIn('official_name', [$origin, $destination])->whereNull('accepted_at')->update(['accepted_at' => now()]);
        } else {
            $final = in_array($code, (array) config('rides.wasl.trip_final_codes'), true) || $attempts >= count((array) config('rides.wasl.trip_backoff_s'));
            $message = $result['body']['resultMsg'] ?? null;
            $ride->forceFill([
                'wasl_status' => $final ? WaslTripStatus::Failed : WaslTripStatus::Pending,
                'wasl_result_code' => $code ?? 'error',
                'wasl_result_message' => is_string($message) ? mb_substr($message, 0, 255) : null,
            ]);
        }
        $ride->forceFill(['wasl_attempts' => $attempts, 'wasl_last_attempt_at' => now()])->save();

        return $ride;
    }

    /** The admin's "send again" (a mapped city, fixed data): a fresh set of tries. */
    public function retry(Ride $ride): Ride
    {
        if ($ride->wasl_status === WaslTripStatus::Sent || $ride->wasl_status === WaslTripStatus::NotRequired || $ride->wasl_status === null) {
            return $ride;
        }
        $ride->forceFill(['wasl_status' => WaslTripStatus::Pending, 'wasl_attempts' => 0, 'wasl_last_attempt_at' => null])->save();

        return $this->send($ride);
    }

    /** @return array<string, mixed> the fields Jawad sends, from what the ride recorded */
    private function body(Ride $ride, string $origin, string $destination): array
    {
        $rating = (float) (RiderRideProfile::query()->whereKey($ride->user_id)->value('rating_avg') ?? 0);

        return [
            'tripId' => $ride->number,
            'driverId' => $ride->driver?->national_id,
            'sequenceNumber' => $ride->vehicle?->sequence_number,
            'distanceInMeters' => max(1, (int) $ride->metered_distance_m),
            'durationInSeconds' => max(1, (int) $ride->metered_duration_s),
            'customerRating' => $rating,
            'customerWaitingTimeInSeconds' => max(1, (int) $ride->pickup_waiting_s),
            'originLatitude' => $ride->origin_lat,
            'originLongitude' => $ride->origin_lng,
            'originCityNameInArabic' => $origin,
            'destinationLatitude' => (float) $ride->destination_lat,
            'destinationLongitude' => (float) $ride->destination_lng,
            'destinationCityNameInArabic' => $destination,
            'startedWhen' => WaslClient::time($ride->requested_at),
            'driverAssignTime' => WaslClient::time($ride->accepted_at),
            'driverArrivalTime' => WaslClient::time($ride->arrived_at ?? $ride->started_at),
            'pickupTimestamp' => WaslClient::time($ride->started_at),
            'dropoffTimestamp' => WaslClient::time($ride->completed_at),
            'tripCost' => round((int) $ride->final_total_minor / 100, 2),
        ];
    }
}
