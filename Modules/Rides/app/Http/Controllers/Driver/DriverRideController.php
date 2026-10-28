<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\CancelReasonActor;
use Modules\Rides\Enums\OfferStatus;
use Modules\Rides\Enums\RatingTarget;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Http\Resources\DriverRideResource;
use Modules\Rides\Http\Resources\RideResource;
use Modules\Rides\Services\Dispatch\NextTrip;
use Modules\Rides\Services\Geo\RideProgress;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideBid;
use Modules\Rides\Models\RideCancelReason;
use Modules\Rides\Models\RideOffer;
use Modules\Rides\Models\RideRatingTag;
use Modules\Rides\Models\RiderRideProfile;
use Modules\Rides\Services\Bidding\BidPricing;
use Modules\Rides\Services\Bidding\BiddingService;
use Modules\Rides\Services\Dispatch\DispatchEngine;
use Modules\Rides\Services\Chat\RideChatService;
use Modules\Rides\Services\Lifecycle\RideCancellationService;
use Modules\Rides\Services\Lifecycle\RideRatingService;
use Modules\Rides\Services\Lifecycle\RideTripService;

/**
 * The driver app's ride screens (docs/rides-plan.md §17): the request card with its timer, accept /
 * decline, then arrived → PIN → started → stops → end → cash, a step back, a no-show, and rating
 * the rider. Writes take an `Idempotency-Key` — a weak network may send the same tap twice.
 */
class DriverRideController extends Controller
{
    /** Requests waiting for this driver now (the push carries the same; the app polls this as a backup). */
    public function offers(Request $request): JsonResponse
    {
        $offers = RideOffer::query()->where('driver_id', $this->driver($request)->id)
            ->where('status', OfferStatus::Sent->value)->where('expires_at', '>', now())
            ->with('ride')->orderBy('expires_at')->get()
            ->map(fn (RideOffer $o) => $this->presentOffer($o));

        return ApiResponse::success($offers->values(), __('api.retrieved'));
    }

    public function accept(Request $request, string $uuid, DispatchEngine $dispatch): JsonResponse
    {
        $ride = $dispatch->accept($this->driver($request), $uuid, $request->header('Idempotency-Key'));

        return ApiResponse::success($this->present($ride, $request), __('rides.messages.accepted'));
    }

    /** Bidding: my own price for this request (equal to the rider's = accepting it). */
    public function bid(Request $request, string $uuid, BiddingService $bidding): JsonResponse
    {
        $data = $request->validate(['price_minor' => ['required', 'integer', 'min:1']]);
        $result = $bidding->counter($this->driver($request), $uuid, (int) $data['price_minor'], $request->header('Idempotency-Key'));

        return $result instanceof Ride
            ? ApiResponse::success(['ride' => $this->present($result, $request), 'bid' => null], __('rides.messages.accepted'))
            : ApiResponse::success(['ride' => null, 'bid' => ['uuid' => $result->uuid, 'status' => $result->status->value, 'price_minor' => $result->bid_price_minor, 'expires_at' => $result->expires_at?->toIso8601String()]], __('rides.messages.bid_sent'), 201);
    }

    public function decline(Request $request, string $uuid, DispatchEngine $dispatch): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:32']]);
        $dispatch->decline($this->driver($request), $uuid, $data['reason'] ?? null);

        return ApiResponse::success(null, __('api.updated'));
    }

    public function current(Request $request): JsonResponse
    {
        $ride = Ride::query()->where('driver_id', $this->driver($request)->id)
            ->whereIn('status', [RideStatus::Accepted->value, RideStatus::Arrived->value, RideStatus::InProgress->value])
            // The ride being driven first — a next trip taken meanwhile waits behind it (Block 06).
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")->latest('id')->first()
            // Cash still to confirm on the last ride: the app stays on the collect screen.
            ?? Ride::query()->where('driver_id', $this->driver($request)->id)->where('payment_status', 'cash_due')->latest('id')->first();

        return ApiResponse::success($ride === null ? null : $this->present($ride, $request), __('api.retrieved'));
    }

    public function index(Request $request): JsonResponse
    {
        $page = Ride::query()->where('driver_id', $this->driver($request)->id)
            ->whereIn('status', [RideStatus::Completed->value, RideStatus::Canceled->value, RideStatus::NoShow->value])
            ->with(['category.translations', 'currency'])->latest('id')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 15))));

        return ApiResponse::success([
            'items' => $page->getCollection()->map(fn (Ride $r) => [
                'uuid' => $r->uuid,
                'number' => $r->number,
                'status' => $r->status->value,
                'category' => $r->category?->translated('name'),
                'origin_address' => $r->origin_address,
                'destination_address' => $r->destination_address,
                'earning_minor' => $r->driver_net_minor,
                // D-204: the rider's tip, on its own (all of it the captain's).
                'tip_minor' => $r->tip_minor,
                'payment_method' => $r->payment_method->value,
                'currency' => $r->currency?->symbol,
                'completed_at' => ($r->completed_at ?? $r->canceled_at)?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ], __('api.retrieved'));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->present($this->find($request, $uuid), $request), __('api.retrieved'));
    }

    public function arrive(Request $request, string $uuid, RideTripService $trips): JsonResponse
    {
        [$lat, $lng] = $this->point($request);
        $ride = $trips->arrive($this->driver($request), $this->find($request, $uuid), $lat, $lng, $request->header('Idempotency-Key'));

        return ApiResponse::success($this->present($ride, $request), __('api.updated'));
    }

    public function start(Request $request, string $uuid, RideTripService $trips): JsonResponse
    {
        $request->validate(['pin' => ['nullable', 'digits:4']]);
        [$lat, $lng] = $this->point($request);
        $ride = $trips->start($this->driver($request), $this->find($request, $uuid), $request->input('pin'), $lat, $lng, $request->header('Idempotency-Key'));

        return ApiResponse::success($this->present($ride, $request), __('api.updated'));
    }

    public function stop(Request $request, string $uuid, int $seq, RideTripService $trips): JsonResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['arrived', 'departed'])]]);
        $ride = $trips->stop($this->driver($request), $this->find($request, $uuid), $seq, $data['action'] === 'arrived');

        return ApiResponse::success($this->present($ride, $request), __('api.updated'));
    }

    public function end(Request $request, string $uuid, RideTripService $trips): JsonResponse
    {
        // `early`: the captain confirmed ending before the destination (the rider getting off sooner).
        $request->validate(['odometer_m' => ['nullable', 'integer', 'min:0', 'max:2000000'], 'early' => ['nullable', 'boolean']]);
        [$lat, $lng] = $this->point($request);
        $ride = $trips->end(
            $this->driver($request), $this->find($request, $uuid), $lat, $lng,
            $request->filled('odometer_m') ? $request->integer('odometer_m') : null, $request->header('Idempotency-Key'), $request->boolean('early'),
        );

        return ApiResponse::success($this->present($ride, $request), __('rides.messages.completed'));
    }

    public function collectCash(Request $request, string $uuid, RideTripService $trips): JsonResponse
    {
        $data = $request->validate(['received_minor' => ['required', 'integer', 'min:0', 'max:100000000']]);
        $ride = $trips->collectCash($this->driver($request), $this->find($request, $uuid), (int) $data['received_minor']);

        return ApiResponse::success($this->present($ride, $request), __('api.updated'));
    }

    public function cancel(Request $request, string $uuid, RideCancellationService $service): JsonResponse
    {
        $data = $request->validate([
            'reason_id' => ['required', 'integer', Rule::exists('ride_cancel_reasons', 'id')->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $service->byDriver($this->driver($request), $this->find($request, $uuid), (int) $data['reason_id'], $data['note'] ?? null, $request->header('Idempotency-Key'));

        return ApiResponse::success(null, __('rides.messages.released'));
    }

    public function noShow(Request $request, string $uuid, RideCancellationService $service): JsonResponse
    {
        $ride = $service->noShow($this->driver($request), $this->find($request, $uuid), $request->header('Idempotency-Key'));

        return ApiResponse::success($this->present($ride, $request), __('api.updated'));
    }

    public function rate(Request $request, string $uuid, RideRatingService $service): JsonResponse
    {
        $data = $request->validate([
            // O-41: the overall stars, or a score per criterion (their average becomes the stars).
            'stars' => ['required_without:scores', 'nullable', 'numeric', 'between:1,5'],
            'scores' => ['nullable', 'array', 'max:10'],
            'scores.*' => ['integer', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->byDriver(
            $this->driver($request), $this->find($request, $uuid), isset($data['stars']) ? (float) $data['stars'] : null,
            array_map('intval', $data['tags'] ?? []), $data['comment'] ?? null, $data['scores'] ?? [],
        );

        return ApiResponse::success(null, __('rides.messages.rated'), 201);
    }

    public function messages(Request $request, string $uuid, RideChatService $chat): JsonResponse
    {
        $ride = $this->find($request, $uuid);

        return ApiResponse::success([
            'open' => $chat->isOpen($ride),
            'quick_replies' => RideChatService::quickReplies($request->user()),
            'messages' => $chat->messages($ride, $request->user()),
        ], __('api.retrieved'));
    }

    public function sendMessage(Request $request, string $uuid, RideChatService $chat): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'required_without:quick', 'string', 'max:1000'],
            'quick' => ['nullable', 'string', 'max:32'],
            'client_id' => ['nullable', 'string', 'max:64'],
        ]);

        return ApiResponse::success($chat->send($this->find($request, $uuid), $request->user(), $data['body'] ?? null, $data['quick'] ?? null, $data['client_id'] ?? null), __('api.created'), 201);
    }

    public function cancelReasons(): JsonResponse
    {
        $reasons = RideCancelReason::query()->where('status', true)
            ->whereIn('actor', [CancelReasonActor::Driver->value, CancelReasonActor::Both->value])
            ->with('translations')->orderBy('sort_order')->get()
            ->map(fn (RideCancelReason $r) => ['id' => $r->id, 'label' => $r->translated('label'), 'stages' => $r->stages, 'requires_note' => $r->requires_note]);

        return ApiResponse::success($reasons, __('api.retrieved'));
    }

    public function ratingTags(): JsonResponse
    {
        $tags = RideRatingTag::query()->where('status', true)->where('target', RatingTarget::Rider->value)
            ->with('translations')->orderBy('sort_order')->get()
            ->map(fn (RideRatingTag $t) => ['id' => $t->id, 'label' => $t->translated('label'), 'sentiment' => $t->sentiment]);

        return ApiResponse::success($tags, __('api.retrieved'));
    }

    /** @return array<string, mixed> */
    private function presentOffer(RideOffer $offer): array
    {
        $ride = $offer->ride;
        $profile = RiderRideProfile::query()->find($ride->user_id);

        return [
            'uuid' => $offer->uuid,
            'ride_uuid' => $ride->uuid,
            'expires_at' => $offer->expires_at->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($offer->expires_at, false)),
            'ttl_s' => (int) $offer->sent_at->diffInSeconds($offer->expires_at),
            'pickup_distance_m' => $offer->distance_m,
            'pickup_eta_s' => $offer->eta_s,
            'earning_minor' => $offer->driver_net_minor,
            // O-28: how the earning is made — the fare, DORR's service fee (commission), the tax — before accepting.
            'money' => ($b = $ride->snapshot('breakdown')) === null ? null : [
                'fare_minor' => (int) ($b['fare'] ?? 0),
                'service_fee_minor' => (int) ($b['commission'] ?? 0),
                'tax_minor' => (int) ($b['tax'] ?? 0),
                'tax_on_driver' => $ride->snapshot('tax.borne_by') !== 'rider',
                'net_minor' => (int) ($b['driver_net'] ?? $offer->driver_net_minor),
            ],
            'rider_rating' => $profile !== null && $profile->rating_count > 0 ? (float) $profile->rating_avg : null,
            'bid' => $ride->status === RideStatus::Bidding ? $this->bidCard($ride, $offer) : null,
            // D-INN-07: the rider chose their favourite captains and this captain is one of them.
            'from_circle' => in_array($offer->driver_id, array_map('intval', (array) $ride->circle_driver_ids), true),
            // Block 06: offered while finishing a ride — it starts once that ride ends (a quiet card, not a full-screen ring).
            'next_trip' => ($now = NextTrip::drivingNow($offer->driver_id)) === null || $now->id === $ride->id ? null : [
                'after_ride_uuid' => $now->uuid,
                'finish_in_s' => RideProgress::of($now)['eta_s'] ?? null,
            ],
            ...RideResource::common($ride),
        ];
    }

    /**
     * What a driver may offer on a bidding request: the rider's price, the highest they may ask,
     * the quick +5 / +10 / +15, how the net is worked out, and their own live offer.
     *
     * @return array<string, mixed>
     */
    private function bidCard(Ride $ride, RideOffer $offer): array
    {
        $policy = (array) $ride->snapshot('policy', []);
        $riderPrice = (int) $ride->rider_offer_minor;
        $mine = RideBid::query()->where('ride_id', $ride->id)->where('driver_id', $offer->driver_id)->latest('id')->first();
        $max = (int) ($policy['bids.max_attempts_per_driver'] ?? 0);

        return [
            'rider_price_minor' => $riderPrice,
            'cap_minor' => BidPricing::cap($policy, $riderPrice),
            'steps_minor' => array_values((array) ($policy['bids.counter_steps_minor'] ?? [500, 1000, 1500])),
            'net_at_rider_price_minor' => BidPricing::driverNet($ride, $riderPrice),
            'commission' => ['type' => $policy['price.commission_type'] ?? 'percent', 'value' => (int) ($policy['price.commission_value'] ?? 0)],
            'tax' => $ride->snapshot('tax') === null ? null : ['base' => $ride->snapshot('tax.base'), 'rate_bp' => (int) $ride->snapshot('tax.rate_bp'), 'borne_by' => $ride->snapshot('tax.borne_by')],
            'my_bid' => $mine === null ? null : ['uuid' => $mine->uuid, 'status' => $mine->status->value, 'price_minor' => $mine->bid_price_minor, 'expires_at' => $mine->expires_at?->toIso8601String()],
            'attempts_left' => $max === 0 ? null : max(0, $max - RideBid::query()->where('ride_id', $ride->id)->where('driver_id', $offer->driver_id)->count()),
        ];
    }

    /** @return array{0: float, 1: float} */
    private function point(Request $request): array
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return [(float) $data['lat'], (float) $data['lng']];
    }

    private function find(Request $request, string $uuid): Ride
    {
        return Ride::query()->where('uuid', $uuid)->where('driver_id', $this->driver($request)->id)->first()
            ?? throw new RidesException('ride_not_found', 404);
    }

    /** @return array<string, mixed> */
    private function present(Ride $ride, Request $request): array
    {
        return (new DriverRideResource($ride->loadMissing(['rider', 'stops'])))->resolve($request);
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
