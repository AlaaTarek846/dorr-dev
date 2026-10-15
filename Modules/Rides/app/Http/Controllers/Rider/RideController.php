<?php

namespace Modules\Rides\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Rides\Enums\CancelReasonActor;
use Modules\Rides\Enums\RatingTarget;
use Modules\Rides\Enums\RidePaymentMethod;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Http\Resources\RideQuoteResource;
use Modules\Rides\Http\Resources\RideResource;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCancelReason;
use Modules\Rides\Models\RideRatingTag;
use Modules\Rides\Services\Bidding\BiddingService;
use Modules\Rides\Services\Chat\RideChatService;
use Modules\Rides\Services\Features\RideFeatures;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Geo\PickupPoints;
use Modules\Rides\Services\Lifecycle\RideCancellationService;
use Modules\Rides\Services\Lifecycle\RideRatingService;
use Modules\Rides\Services\Lifecycle\RideRequestService;
use Modules\Rides\Services\Payment\RidePaymentChangeService;
use Modules\Rides\Services\Payment\RideTipService;
use Modules\Rides\Services\Pricing\QuoteEngine;
use Modules\User\Models\User;

/**
 * The rider's rides (docs/rides-plan.md §15): price a trip, confirm a quote, follow the ride,
 * cancel (with the fee shown first), rate, and the history. Writes take an `Idempotency-Key`.
 */
class RideController extends Controller
{
    public function quotes(Request $request, QuoteEngine $engine): JsonResponse
    {
        $point = fn (string $p, bool $required) => [
            $p => [$required ? 'required' : 'nullable', 'array'],
            "$p.lat" => [$required ? 'required' : "required_with:$p", 'numeric', 'between:-90,90'],
            "$p.lng" => [$required ? 'required' : "required_with:$p", 'numeric', 'between:-180,180'],
            "$p.address" => ['nullable', 'string', 'max:255'],
            // R-16..R-18: an approved pickup / drop-off point picked from `GET pickup-points`.
            "$p.point_id" => ['nullable', 'integer', 'min:1'],
        ];
        $data = $request->validate([
            ...$point('origin', true),
            ...$point('destination', false),
            'stops' => ['nullable', 'array', 'max:5'],
            'stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'stops.*.address' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:32'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);
        if (! empty($data['stops']) && empty($data['destination'])) {
            throw new RidesException('stops_need_destination', 422);
        }

        $quotes = $engine->quote($this->rider($request), $data);

        return ApiResponse::success(RideQuoteResource::collection($quotes)->resolve($request), __('api.retrieved'));
    }

    /**
     * R-16..R-18, D-111: the approved points around a place — `purpose` = pickup | dropoff. Empty when
     * the place isn't in an airport / pickup-only zone, or the feature is off there.
     */
    public function pickupPoints(Request $request, PickupPoints $points, CityResolver $cities, RideFeatures $features): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'purpose' => ['nullable', Rule::in([PickupPoints::PICKUP, PickupPoints::DROPOFF])],
        ]);
        $rider = $this->rider($request);
        $cityId = $cities->cityAt((float) $data['lat'], (float) $data['lng']);
        $countryId = ($cityId !== null ? $cities->countryOf($cityId) : null) ?? $rider->country_id;
        if ($countryId === null || ! $features->allows($countryId, $cityId, 'pickup_points')) {
            return ApiResponse::success(['zone' => null, 'required' => false, 'points' => []], __('api.retrieved'));
        }

        return ApiResponse::success($points->around($countryId, (float) $data['lat'], (float) $data['lng'], $data['purpose'] ?? PickupPoints::PICKUP), __('api.retrieved'));
    }

    public function store(Request $request, RideRequestService $service): JsonResponse
    {
        $data = $request->validate([
            'quote_uuid' => ['required', 'uuid'],
            'fingerprint' => ['required', 'string', 'size:64'],
            'payment_method' => ['required', Rule::enum(RidePaymentMethod::class)],
            'rider_price_minor' => ['nullable', 'integer', 'min:1'],
            // Booked ahead (R-12): an ISO time with its offset; the limits are the policy's.
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            // D-60, D-61: how many ride (the category's seats are checked), a short note for the captain.
            'passengers' => ['nullable', 'integer', 'min:1', 'max:20'],
            'pickup_note' => ['nullable', 'string', 'max:255'],
            // R-13: booked for someone else — their name and number, and the rider's own yes (never pre-ticked)
            // that this person agreed to be contacted about the ride.
            'beneficiary' => ['nullable', 'array'],
            'beneficiary.name' => ['required_with:beneficiary', 'string', 'min:2', 'max:60'],
            'beneficiary.phone' => ['required_with:beneficiary', 'string', 'regex:/^\+?[0-9 ]{8,18}$/'],
            'beneficiary.consent' => ['exclude_without:beneficiary', 'accepted'],
            // R-INN-07: offered to the rider's favourite captains first — none listed = all of them.
            'circle' => ['nullable', 'array'],
            'circle.driver_ids' => ['nullable', 'array', 'max:20'],
            'circle.driver_ids.*' => ['integer', 'min:1'],
        ]);

        $ride = $service->request(
            $this->rider($request), $data['quote_uuid'], $data['fingerprint'],
            RidePaymentMethod::from($data['payment_method']), $request->header('Idempotency-Key'),
            isset($data['rider_price_minor']) ? (int) $data['rider_price_minor'] : null,
            isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at'])->utc() : null,
            isset($data['passengers']) ? (int) $data['passengers'] : null,
            $data['pickup_note'] ?? null,
            isset($data['beneficiary']) ? ['name' => (string) $data['beneficiary']['name'], 'phone' => (string) $data['beneficiary']['phone']] : null,
            array_key_exists('circle', $data) && $data['circle'] !== null ? array_map('intval', $data['circle']['driver_ids'] ?? []) : null,
        );

        return ApiResponse::success($this->present($ride, $request), __($ride->scheduled_at !== null ? 'rides.messages.scheduled' : 'rides.messages.requested'), 201);
    }

    /** The rider's ride that hasn't ended yet, if any — the app opens on it. */
    public function current(Request $request): JsonResponse
    {
        $ride = Ride::query()->where('user_id', $this->rider($request)->id)
            ->whereIn('status', RideStatus::activeValues())->latest('id')->first();

        return ApiResponse::success($ride === null ? null : $this->present($ride, $request), __('api.retrieved'));
    }

    /** My rides (R-163..165): `tab` = current | scheduled | past (all when left out). */
    public function index(Request $request): JsonResponse
    {
        $tab = $request->validate(['tab' => ['nullable', Rule::in(['current', 'scheduled', 'past'])]])['tab'] ?? null;
        $page = Ride::query()->where('user_id', $this->rider($request)->id)
            ->when($tab === 'current', fn ($q) => $q->whereIn('status', RideStatus::activeValues()))
            ->when($tab === 'scheduled', fn ($q) => $q->where('status', RideStatus::Scheduled->value))
            ->when($tab === 'past', fn ($q) => $q->whereIn('status', array_map(fn (RideStatus $s) => $s->value, array_filter(RideStatus::cases(), fn (RideStatus $s) => $s->isFinal()))))
            ->with(['driver', 'reservedDriver', 'category.translations', 'currency'])
            ->when($tab === 'scheduled', fn ($q) => $q->orderBy('scheduled_at'), fn ($q) => $q->latest('id'))
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 15))));

        return ApiResponse::success([
            'items' => $page->getCollection()->map(fn (Ride $r) => [
                'uuid' => $r->uuid,
                'number' => $r->number,
                'status' => $r->status->value,
                'category' => $r->category?->translated('name'),
                'origin_address' => $r->origin_address,
                'destination_address' => $r->destination_address,
                'total_minor' => $r->final_total_minor ?? $r->agreed_price_minor,
                'currency' => $r->currency?->symbol,
                'driver_name' => $r->driver?->name ?? $r->reservedDriver?->name,
                'beneficiary_name' => $r->beneficiary['name'] ?? null,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'scheduled_at' => $r->scheduled_at?->toIso8601String(),
                'free_cancel_until' => $r->scheduled_at === null ? null : $r->freeCancelUntil()?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ], __('api.retrieved'));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->present($this->find($request, $uuid), $request), __('api.retrieved'));
    }

    public function cancelPreview(Request $request, string $uuid, RideCancellationService $service): JsonResponse
    {
        return ApiResponse::success($service->preview($this->rider($request), $this->find($request, $uuid)), __('api.retrieved'));
    }

    public function cancel(Request $request, string $uuid, RideCancellationService $service): JsonResponse
    {
        $data = $request->validate([
            'reason_id' => ['nullable', 'integer', Rule::exists('ride_cancel_reasons', 'id')->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $ride = $service->byRider($this->rider($request), $this->find($request, $uuid), $data['reason_id'] ?? null, $data['note'] ?? null, $request->header('Idempotency-Key'));

        return ApiResponse::success($this->present($ride, $request), __('rides.messages.canceled'));
    }

    public function rate(Request $request, string $uuid, RideRatingService $service): JsonResponse
    {
        $data = $request->validate([
            'stars' => ['required', 'numeric', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->byRider($this->rider($request), $this->find($request, $uuid), (float) $data['stars'], array_map('intval', $data['tags'] ?? []), $data['comment'] ?? null);

        return ApiResponse::success(null, __('rides.messages.rated'), 201);
    }

    /** R-61: wallet ↔ cash before the trip starts; the lines, the hold and the captain follow. */
    public function changePayment(Request $request, string $uuid, RidePaymentChangeService $service): JsonResponse
    {
        $data = $request->validate(['payment_method' => ['required', Rule::enum(RidePaymentMethod::class)]]);
        $ride = $service->change($this->rider($request), $this->find($request, $uuid), RidePaymentMethod::from($data['payment_method']), $request->header('Idempotency-Key'));

        return ApiResponse::success($this->present($ride, $request), __('rides.messages.payment_changed'));
    }

    /** R-144: an optional tip after the ride, from the wallet, all of it the captain's. */
    public function tip(Request $request, string $uuid, RideTipService $tips): JsonResponse
    {
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:1']]);
        $ride = $tips->tip($this->rider($request), $this->find($request, $uuid), (int) $data['amount_minor'], $request->header('Idempotency-Key'));

        return ApiResponse::success($this->present($ride, $request), __('rides.messages.tipped'));
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

    public function bids(Request $request, string $uuid, BiddingService $bidding): JsonResponse
    {
        $ride = $this->find($request, $uuid);

        return ApiResponse::success([
            'rider_price_minor' => $ride->rider_offer_minor,
            'floor_minor' => $ride->bid_floor_minor,
            'suggested_minor' => $ride->bid_suggested_minor,
            ...$bidding->board($ride),
        ], __('api.retrieved'));
    }

    public function acceptBid(Request $request, string $uuid, string $bid, BiddingService $bidding): JsonResponse
    {
        $ride = $bidding->acceptBid($this->rider($request), $this->find($request, $uuid), $bid, $request->header('Idempotency-Key'));

        return ApiResponse::success($this->present($ride, $request), __('rides.messages.bid_accepted'));
    }

    public function declineBid(Request $request, string $uuid, string $bid, BiddingService $bidding): JsonResponse
    {
        $bidding->declineBid($this->rider($request), $this->find($request, $uuid), $bid);

        return ApiResponse::success(null, __('api.updated'));
    }

    public function raise(Request $request, string $uuid, BiddingService $bidding): JsonResponse
    {
        $data = $request->validate(['price_minor' => ['required', 'integer', 'min:1']]);
        $ride = $bidding->raise($this->rider($request), $this->find($request, $uuid), (int) $data['price_minor']);

        return ApiResponse::success($this->present($ride, $request), __('api.updated'));
    }

    public function cancelReasons(): JsonResponse
    {
        $reasons = RideCancelReason::query()->where('status', true)
            ->whereIn('actor', [CancelReasonActor::Rider->value, CancelReasonActor::Both->value])
            ->with('translations')->orderBy('sort_order')->get()
            ->map(fn (RideCancelReason $r) => ['id' => $r->id, 'label' => $r->translated('label'), 'stages' => $r->stages, 'requires_note' => $r->requires_note]);

        return ApiResponse::success($reasons, __('api.retrieved'));
    }

    public function ratingTags(): JsonResponse
    {
        $tags = RideRatingTag::query()->where('status', true)->where('target', RatingTarget::Driver->value)
            ->with('translations')->orderBy('sort_order')->get()
            ->map(fn (RideRatingTag $t) => ['id' => $t->id, 'label' => $t->translated('label'), 'sentiment' => $t->sentiment]);

        return ApiResponse::success($tags, __('api.retrieved'));
    }

    private function find(Request $request, string $uuid): Ride
    {
        return Ride::query()->where('uuid', $uuid)->where('user_id', $this->rider($request)->id)->first()
            ?? throw new RidesException('ride_not_found', 404);
    }

    /** @return array<string, mixed> */
    private function present(Ride $ride, Request $request): array
    {
        return (new RideResource($ride->loadMissing('driver')))->resolve($request);
    }

    private function rider(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
