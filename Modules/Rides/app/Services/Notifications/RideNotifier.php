<?php

namespace Modules\Rides\Services\Notifications;

use App\Models\Currency;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Models\Driver;
use Modules\Rides\Events\DriverChanged;
use Modules\Rides\Events\RideChanged;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideOffer;
use Throwable;

/**
 * Tells the rider and the driver what happened (docs/rides-plan.md §16): the ride channel refreshes
 * both apps; the in-app list / real-time / push go through NotificationCenter in each person's
 * language. Everything waits for the transaction to commit and never fails the action.
 */
class RideNotifier
{
    public function __construct(private readonly NotificationCenter $center) {}

    public function changed(Ride $ride, string $event): void
    {
        DB::afterCommit(function () use ($ride, $event) {
            try {
                broadcast(RideChanged::of($ride, $event));
                // The captains it concerns hear it on their own channel: the ride's driver, the one who
                // reserved it, and anyone asked in the last few minutes (their request may be gone now).
                $drivers = RideOffer::query()->where('ride_id', $ride->id)->where('sent_at', '>=', now()->subMinutes(5))->pluck('driver_id')
                    ->push($ride->driver_id)->push($ride->reserved_driver_id)->filter()->unique();
                foreach ($drivers as $driverId) {
                    broadcast(new DriverChanged((int) $driverId, $ride->uuid, $event));
                }
            } catch (Throwable $e) {
                Log::warning('[Rides] ride broadcast failed: '.$e->getMessage());
            }
        });
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function rider(Ride $ride, string $key, array $variables = []): void
    {
        $this->center->send($ride->rider, 'ride.'.$key, 'ride_'.$key.'_title', 'ride_'.$key.'_body', $variables + ['number' => $ride->number], [
            'type' => 'ride', 'ride_uuid' => $ride->uuid, 'status' => $ride->status->value,
        ]);
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function driver(Driver $driver, Ride $ride, string $key, array $variables = []): void
    {
        $this->center->send($driver, 'ride.'.$key, 'ride_'.$key.'_title', 'ride_'.$key.'_body', $variables + ['number' => $ride->number], [
            'type' => 'ride', 'ride_uuid' => $ride->uuid, 'status' => $ride->status->value,
        ]);
    }

    /** A new request for a driver: high-priority push with its accept action (the driver app builds it, §17.2). */
    public function offer(RideOffer $offer, Ride $ride): void
    {
        $this->center->send($offer->driver, 'ride.offer', 'ride_offer_title', 'ride_offer_body', [
            'distance' => number_format($offer->distance_m / 1000, 1),
            'amount' => $this->money((int) $offer->driver_net_minor, $ride),
        ], [
            'type' => 'ride_offer',
            // Block 06: a next trip, offered while the captain is still driving — the app shows it quietly.
            'next_trip' => \Modules\Rides\Services\Dispatch\NextTrip::drivingNow((int) $offer->driver_id) !== null,
            'offer_uuid' => $offer->uuid,
            'ride_uuid' => $ride->uuid,
            'expires_at' => $offer->expires_at->toIso8601String(),
        ], true, [
            // A request must reach a closed phone at once, and be worthless once it has expired.
            'priority' => 10,
            'ttl' => max(5, (int) now()->diffInSeconds($offer->expires_at, false)),
            'android_visibility' => 1,
        ]);
    }

    public function money(int $minor, Ride $ride): string
    {
        $currency = $ride->relationLoaded('currency') ? $ride->currency : Currency::query()->find($ride->currency_id);
        $places = (int) ($currency?->decimal_places ?? 2);

        return number_format($minor / (10 ** $places), $places).' '.($currency?->symbol ?? '');
    }
}
