<?php

namespace Modules\Rides\Services\Cases;

use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideAdjustment;
use Modules\Rides\Models\RideEvent;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Models\RideTrack;
use Modules\Rides\Support\Geo;

/**
 * What the system itself recorded about a ride, kept with a case the moment it is opened (R-137, R-138): the
 * server-time milestones with where the captain was, how long the waiting ran, the route, and the money
 * lines. Taken once and frozen, so a later change to the ride can't rewrite what was in front of the people
 * deciding. Nothing here identifies the other person (no phone, no account) — see `forViewer()`.
 */
class CaseEvidence
{
    /** The milestones worth seeing in a dispute. */
    private const MILESTONES = ['accepted', 'arrived', 'started', 'completed', 'canceled', 'no_show'];

    /** @return array<string, mixed> */
    public function forRide(Ride $ride): array
    {
        $events = RideEvent::query()->where('ride_id', $ride->id)->whereIn('type', self::MILESTONES)->orderBy('id')->get();
        $timeline = $events->map(function (RideEvent $e) use ($ride) {
            $row = ['event' => $e->type, 'at' => $e->created_at?->toIso8601String(), 'by' => $e->actor_type];
            if ($e->lat !== null && $e->lng !== null) {
                $row['lat'] = (float) $e->lat;
                $row['lng'] = (float) $e->lng;
                if ($e->type === 'arrived') {
                    $row['distance_to_pickup_m'] = (int) round(Geo::metres((float) $ride->origin_lat, (float) $ride->origin_lng, (float) $e->lat, (float) $e->lng));
                }
            }

            return $row;
        })->values()->all();

        $track = RideTrack::query()->find($ride->id);

        return [
            'ride' => ['number' => $ride->number, 'status' => $ride->status->value, 'payment_method' => $ride->payment_method->value, 'payment_status' => $ride->payment_status->value],
            'pickup' => ['address' => $ride->origin_address, 'lat' => (float) $ride->origin_lat, 'lng' => (float) $ride->origin_lng],
            'dropoff' => $ride->destination_lat === null ? null : ['address' => $ride->destination_address, 'lat' => (float) $ride->destination_lat, 'lng' => (float) $ride->destination_lng],
            'timeline' => $timeline,
            'waiting' => ['pickup_s' => (int) $ride->pickup_waiting_s, 'stops_s' => (int) $ride->stops_waiting_s, 'free_s' => (int) $ride->policy('waiting.free_s', 0)],
            'route' => [
                'planned_m' => $ride->planned_distance_m,
                'driven_m' => $track?->track_distance_m ?? $ride->metered_distance_m,
                'points' => $track?->points_count,
                'gps_gap_s' => (int) $ride->gps_gap_s,
                'polyline' => $track?->polyline,
            ],
            'lines' => RidePriceLine::query()->where('ride_id', $ride->id)->where('phase', 'final')->orderBy('sort_order')->get()
                ->map(fn (RidePriceLine $l) => ['code' => $l->code, 'amount_minor' => (int) $l->amount_minor, 'visible_to' => $l->visible_to])->values()->all(),
            'adjustments' => RideAdjustment::query()->where('ride_id', $ride->id)->get()
                ->map(fn (RideAdjustment $a) => ['type' => $a->type, 'amount_minor' => (int) $a->amount_minor, 'affects' => $a->affects])->values()->all(),
            'total_minor' => $ride->final_total_minor,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The same evidence as one side of the case sees it: only the money lines meant for them.
     *
     * @param  array<string, mixed>|null  $evidence
     * @return array<string, mixed>|null
     */
    public function forViewer(?array $evidence, string $viewer): ?array
    {
        if ($evidence === null || $viewer === 'admin') {
            return $evidence;
        }
        $mine = $viewer === 'driver' ? 'driver' : 'rider';
        $evidence['lines'] = array_values(array_filter((array) ($evidence['lines'] ?? []), fn (array $l) => in_array($l['visible_to'] ?? 'both', ['both', $mine], true)));
        // The driver's cut and commission are theirs alone; the rider sees what they paid.
        $evidence['adjustments'] = array_values(array_filter((array) ($evidence['adjustments'] ?? []), fn (array $a) => in_array($a['affects'] ?? '', ['both', $mine], true)));

        return $evidence;
    }
}
