<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Enums\WaslTripStatus;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideAdjustment;
use Modules\Rides\Models\RideBid;
use Modules\Rides\Models\RideEvent;
use Modules\Rides\Models\RideMessage;
use Modules\Rides\Models\RideSafetyIncident;
use Modules\Rides\Models\RideShare;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Models\RidePriceLine;
use Modules\Rides\Models\RidePriceOffer;
use Modules\Rides\Models\RideSettlementItem;
use Modules\Rides\Models\WaslLog;
use Modules\Rides\Services\Geo\PickupPoints;
use Modules\Rides\Services\Regulator\Wasl\WaslTripService;

/**
 * Admin → Rides → Rides: every ride with its whole story — the timeline (append-only events), the
 * prices confirmed and final (every line, commission and tax included), the offers sent, the
 * money (holds, adjustments, the driver's settlement), Wasl, and the chat for complaints. The
 * Wasl queue is this list filtered by `wasl_status`, with "send again".
 */
class RideAdminController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('rides', [
            ['view', ['index', 'show', 'heatMap', 'driversMap']],
            ['update', ['waslRetry']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::enum(RideStatus::class)],
            'wasl_status' => ['nullable', Rule::enum(WaslTripStatus::class)],
            'payment_method' => ['nullable', 'in:wallet,card,cash'],
            'category_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);
        $page = Ride::query()
            ->with(['rider', 'driver', 'category.translations', 'currency'])
            ->when($filters['search'] ?? null, function ($q, string $s) {
                $like = '%'.$s.'%';
                $q->where(fn ($q) => $q->where('number', 'like', $like)
                    ->orWhereHas('rider', fn ($q) => $q->where('phone', 'like', $like)->orWhere('name', 'like', $like))
                    ->orWhereHas('driver', fn ($q) => $q->where('phone', 'like', $like)->orWhere('name', 'like', $like)));
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['wasl_status'] ?? null, fn ($q, $v) => $q->where('wasl_status', $v))
            ->when($filters['payment_method'] ?? null, fn ($q, $v) => $q->where('payment_method', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('requested_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('requested_at', '<=', $v.' 23:59:59'))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::fromPaginator($page, $page->getCollection()->map(fn (Ride $r) => [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'number' => $r->number,
            'status' => $r->status->value,
            'category' => $r->category?->translated('name'),
            // O-06 (LeeTaxi's list): the category's colour, the faces, the plate, and the points for the map.
            'category_color' => $r->category?->color,
            'rider' => $r->rider ? ['id' => $r->rider->id, 'name' => $r->rider->name, 'phone' => $r->rider->phone, 'avatar' => rescue(fn () => $r->rider->getSingleMediaUrl('avatar'), null, false) ?: null] : null,
            'driver' => $r->driver ? ['id' => $r->driver->id, 'name' => $r->driver->name, 'phone' => $r->driver->phone, 'avatar' => rescue(fn () => $r->driver->getFirstMediaUrl('avatar'), null, false) ?: null] : null,
            'plate' => trim(($r->vehicle_snapshot['plate_letters'] ?? '').' '.($r->vehicle_snapshot['plate_number'] ?? '')) ?: null,
            'origin' => ['lat' => (float) $r->origin_lat, 'lng' => (float) $r->origin_lng],
            'destination' => $r->destination_lat === null ? null : ['lat' => (float) $r->destination_lat, 'lng' => (float) $r->destination_lng],
            'polyline' => $r->planned_polyline,
            'origin_address' => $r->origin_address,
            'destination_address' => $r->destination_address,
            'payment_method' => $r->payment_method->value,
            'payment_status' => $r->payment_status->value,
            'total_minor' => $r->final_total_minor ?? $r->agreed_price_minor,
            'currency' => $r->currency?->symbol,
            'wasl_status' => $r->wasl_status?->value,
            'wasl_result_code' => $r->wasl_result_code,
            'requested_at' => $r->requested_at?->toIso8601String(),
            'scheduled_at' => $r->scheduled_at?->toIso8601String(),
        ])->values(), __('api.retrieved'));
    }

    /**
     * O-08 (LeeTaxi's heat map): where rides are asked for — every ride of the last `days`, and the ones
     * waiting for a captain now — with the admin map's Mapbox token.
     */
    public function heatMap(Request $request): JsonResponse
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'between:1,365']])['days'] ?? 30);
        $points = fn ($q) => $q->limit(5000)->get(['origin_lat', 'origin_lng'])->map(fn (Ride $r) => [(float) $r->origin_lat, (float) $r->origin_lng])->values();

        return ApiResponse::success([
            'map' => $this->mapSettings(),
            'all' => $points(Ride::query()->where('requested_at', '>=', now()->subDays(max(1, $days)))),
            'pending' => $points(Ride::query()->whereIn('status', [RideStatus::Searching->value, RideStatus::Bidding->value, RideStatus::AwaitingPayment->value])),
        ], __('api.retrieved'));
    }

    /** O-07 (Jawad's drivers map): every captain online or on a ride, where they are, with what the popup shows. */
    public function driversMap(): JsonResponse
    {
        $presences = \Modules\Driver\Models\DriverPresence::query()
            ->whereIn('status', [\Modules\Driver\Enums\PresenceStatus::Online->value, \Modules\Driver\Enums\PresenceStatus::OnTrip->value, \Modules\Driver\Enums\PresenceStatus::Paused->value])
            ->whereNotNull('lat')->with('driver')->limit(2000)->get();
        $ratings = \Modules\Rides\Models\DriverRideProfile::query()->whereIn('driver_id', $presences->pluck('driver_id'))->get()->keyBy('driver_id');

        return ApiResponse::success([
            'map' => $this->mapSettings(),
            'drivers' => $presences->map(fn ($p) => [
                'id' => $p->driver_id,
                'name' => $p->driver?->name,
                'phone' => $p->driver?->phone,
                'avatar' => rescue(fn () => $p->driver?->getFirstMediaUrl('avatar'), null, false) ?: null,
                'status' => $p->status->value,
                'lat' => (float) $p->lat,
                'lng' => (float) $p->lng,
                'heading' => $p->heading ?? null,
                'rating' => ($r = $ratings[$p->driver_id] ?? null) !== null && $r->rating_count > 0 ? round((float) $r->rating_avg, 1) : null,
                'trips' => (int) ($ratings[$p->driver_id]->trips_count ?? 0),
                'last_seen_at' => $p->last_seen_at?->toIso8601String(),
            ])->values(),
        ], __('api.retrieved'));
    }

    /** @return array<string, mixed> the admin's maps draw with Mapbox GL (like LeeTaxi) — its public token and style */
    private function mapSettings(): array
    {
        $settings = \Modules\Rides\Models\RideSetting::current();

        return ['mapbox_token' => $settings->mapboxToken() ?: null, 'mapbox_style' => $settings->mapbox_style ?: 'mapbox/streets-v12'];
    }

    public function show(int $ride): JsonResponse
    {
        $r = Ride::query()->with(['rider', 'driver', 'reservedDriver', 'category.translations', 'currency', 'stops', 'track', 'cancelReason.translations'])->findOrFail($ride);

        return ApiResponse::success([
            'id' => $r->id,
            'uuid' => $r->uuid,
            'number' => $r->number,
            'status' => $r->status->value,
            'category' => $r->category?->translated('name'),
            'pricing_mode' => $r->pricing_mode->value,
            'open_destination' => $r->open_destination,
            'payment_method' => $r->payment_method->value,
            'payment_status' => $r->payment_status->value,
            'currency' => ['code' => $r->currency?->code, 'symbol' => $r->currency?->symbol, 'decimal_places' => (int) ($r->currency?->decimal_places ?? 2)],
            'rider' => $r->rider ? ['id' => $r->rider->id, 'name' => $r->rider->name, 'phone' => $r->rider->phone] : null,
            'driver' => $r->driver ? ['id' => $r->driver->id, 'name' => $r->driver->name, 'phone' => $r->driver->phone] : null,
            // R-13: booked for someone else — who was picked up, and when the rider confirmed their consent.
            'beneficiary' => $r->beneficiary,
            // R-16..R-18: the approved points the trip started / ended at (names in this admin's language).
            'pickup_point' => PickupPoints::present($r->pickup_point),
            'dropoff_point' => PickupPoints::present($r->dropoff_point),
            'vehicle' => $r->vehicle_snapshot,
            // Block 06: fraud signs found at the end (AI-06), how likely it was to be cancelled (AI-10), a booking's
            // lateness warning, the rider's «راحتي» and what the captain couldn't do, and the ride a next trip followed.
            'ai' => [
                'risk_score' => $r->risk_score,
                'risk_flags' => collect((array) $r->risk_flags)->map(fn ($f) => ['code' => $f['code'] ?? null, 'label' => __('rides.fraud.'.($f['code'] ?? 'other')), 'detail' => $f['detail'] ?? []])->values(),
                'cancel_risk' => $r->cancel_risk,
                'late_risk' => $r->late_risk,
                'comfort' => \Modules\Rides\Services\Comfort\ComfortPreferences::present($r),
                'after_ride_id' => $r->after_ride_id,
            ],
            // Booked ahead (R-12, D-264): the time, the free-cancel limit, the captain who reserved it.
            'scheduled' => $r->scheduled_at === null ? null : [
                'at' => $r->scheduled_at->toIso8601String(),
                'free_cancel_until' => $r->freeCancelUntil()?->toIso8601String(),
                'reminded_at' => $r->reminded_at?->toIso8601String(),
                'reserved_driver' => $r->reservedDriver ? ['id' => $r->reservedDriver->id, 'name' => $r->reservedDriver->name, 'phone' => $r->reservedDriver->phone] : null,
                'reserved_at' => $r->reserved_at?->toIso8601String(),
            ],
            'origin' => ['lat' => $r->origin_lat, 'lng' => $r->origin_lng, 'address' => $r->origin_address],
            'destination' => $r->destination_lat === null ? null : ['lat' => $r->destination_lat, 'lng' => $r->destination_lng, 'address' => $r->destination_address],
            'stops' => $r->stops->map->only(['seq', 'lat', 'lng', 'address', 'status', 'arrived_at', 'departed_at', 'wait_s'])->values(),
            'planned' => ['distance_m' => $r->planned_distance_m, 'duration_s' => $r->planned_duration_s, 'polyline' => $r->planned_polyline],
            'metered' => ['distance_m' => $r->metered_distance_m, 'duration_s' => $r->metered_duration_s, 'source' => $r->distance_source?->value],
            'track' => $r->track ? ['polyline' => $r->track->polyline, 'points' => $r->track->points_count, 'track_m' => $r->track->track_distance_m, 'device_m' => $r->track->device_distance_m] : null,
            'money' => [
                'agreed_minor' => $r->agreed_price_minor,
                'final_total_minor' => $r->final_total_minor,
                'commission_minor' => $r->commission_minor,
                'tax_minor' => $r->tax_minor,
                'driver_net_minor' => $r->driver_net_minor,
                'previous_balance_minor' => $r->previous_balance_minor,
                'pickup_waiting_s' => $r->pickup_waiting_s,
            ],
            'lines' => RidePriceLine::query()->where('ride_id', $r->id)->orderBy('phase')->orderBy('sort_order')->get()
                ->map(fn (RidePriceLine $l) => ['phase' => $l->phase, 'code' => $l->code, 'label' => __('rides.lines.'.$l->code), 'amount_minor' => (int) $l->amount_minor, 'visible_to' => $l->visible_to])->values(),
            'events' => RideEvent::query()->where('ride_id', $r->id)->orderBy('id')->get()
                ->map(fn (RideEvent $e) => ['type' => $e->type, 'from' => $e->from_status, 'to' => $e->to_status, 'actor' => $e->actor_type, 'actor_id' => $e->actor_id, 'payload' => $e->payload, 'at' => $e->created_at?->toIso8601String()])->values(),
            'offers' => RideOffer::query()->where('ride_id', $r->id)->with('driver:id,name,phone')->orderBy('id')->get()
                ->map(fn (RideOffer $o) => ['round' => $o->round, 'driver' => $o->driver?->name ?? $o->driver?->phone, 'distance_m' => $o->distance_m, 'status' => $o->status->value, 'sent_at' => $o->sent_at?->toIso8601String(), 'responded_at' => $o->responded_at?->toIso8601String()])->values(),
            // Bidding (§9): the rider's price and each raise, and every driver's offer.
            'bidding' => $r->booking_mode?->value !== 'bid' ? null : [
                'floor_minor' => $r->bid_floor_minor,
                'suggested_minor' => $r->bid_suggested_minor,
                'prices' => RidePriceOffer::query()->where('ride_id', $r->id)->orderBy('id')->get()->map->only(['price_minor', 'reason', 'created_at'])->values(),
                'bids' => RideBid::query()->where('ride_id', $r->id)->with('driver:id,name,phone')->orderBy('id')->get()
                    ->map(fn (RideBid $b) => ['driver' => $b->driver?->name ?? $b->driver?->phone, 'attempt' => $b->attempt, 'rider_price_minor' => $b->rider_price_minor, 'price_minor' => $b->bid_price_minor, 'status' => $b->status->value, 'created_at' => $b->created_at?->toIso8601String()])->values(),
            ],
            'adjustments' => RideAdjustment::query()->where('ride_id', $r->id)->get()->map->only(['type', 'amount_minor', 'affects', 'created_at'])->values(),
            'settlement' => RideSettlementItem::query()->where('ride_id', $r->id)->get()
                ->map(fn (RideSettlementItem $i) => ['type' => $i->type->value, 'amount_minor' => (int) $i->amount_minor, 'status' => $i->status->value, 'release_at' => $i->release_at?->toIso8601String(), 'posted_at' => $i->posted_at?->toIso8601String()])->values(),
            'cancel' => $r->cancel_actor === null ? null : ['actor' => $r->cancel_actor->value, 'stage' => $r->cancel_stage?->value, 'reason' => $r->cancelReason?->translated('label'), 'note' => $r->cancel_note],
            'wasl' => [
                'status' => $r->wasl_status?->value,
                'attempts' => $r->wasl_attempts,
                'result_code' => $r->wasl_result_code,
                'result_message' => $r->wasl_result_message,
                'origin_city' => $r->wasl_origin_city,
                'destination_city' => $r->wasl_destination_city,
                'sent_at' => $r->wasl_sent_at?->toIso8601String(),
                'logs' => WaslLog::query()->where('subject_type', 'ride')->where('subject_id', $r->id)->latest('id')->limit(10)->get()
                    ->map->only(['id', 'type', 'status_code', 'success', 'result_code', 'request', 'response', 'created_at'])->values(),
            ],
            // Safety: every SOS on this ride, and the family links (how often they were opened).
            'safety' => [
                'incidents' => RideSafetyIncident::query()->where('ride_id', $r->id)->orderBy('id')->get()
                    ->map(fn (RideSafetyIncident $i) => ['id' => $i->id, 'reporter_type' => $i->reporter_type, 'status' => $i->status, 'lat' => $i->lat, 'lng' => $i->lng, 'created_at' => $i->created_at?->toIso8601String()])->values(),
                'shares' => RideShare::query()->where('ride_id', $r->id)->orderBy('id')->get()
                    ->map(fn (RideShare $s) => ['sharer_type' => $s->sharer_type, 'views' => $s->views, 'revoked' => $s->revoked_at !== null, 'created_at' => $s->created_at?->toIso8601String()])->values(),
            ],
            'messages' => RideMessage::query()->where('ride_id', $r->id)->orderBy('id')->get()
                ->map(fn (RideMessage $m) => ['from' => $m->sender_type, 'kind' => $m->kind, 'body' => $m->body, 'at' => $m->created_at?->toIso8601String()])->values(),
            'times' => collect(['requested_at', 'search_started_at', 'accepted_at', 'arrived_at', 'started_at', 'completed_at', 'canceled_at', 'expired_at'])
                ->mapWithKeys(fn (string $k) => [$k => $r->{$k}?->toIso8601String()]),
        ], __('api.retrieved'));
    }

    /** Wasl: send this trip again (after mapping its city, or fixing the driver's data). */
    public function waslRetry(int $ride, WaslTripService $wasl, AuditLogger $audit): JsonResponse
    {
        $model = Ride::query()->findOrFail($ride);
        $model = $wasl->retry($model);
        $audit->record('ride.wasl_retry', $model, null, ['wasl_status' => $model->wasl_status?->value, 'result' => $model->wasl_result_code]);

        return $this->show($model->id);
    }
}
