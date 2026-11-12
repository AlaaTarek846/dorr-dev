<?php

namespace Modules\Moving\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Moving\Enums\LoadType;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Enums\OfferStatus;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Http\Requests\MovingRequestRequest;
use Modules\Moving\Http\Resources\MovingInventoryResource;
use Modules\Moving\Http\Resources\MovingOfferResource;
use Modules\Moving\Http\Resources\MovingRequestResource;
use Modules\Moving\Models\MovingCase;
use Modules\Moving\Models\MovingOffer;
use Modules\Moving\Models\MovingRating;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Services\Payment\MovingCheckoutPurpose;
use Modules\Moving\Services\Payment\MovingSettlement;
use Modules\Moving\Services\Policy\MovingPolicies;
use Modules\Moving\Services\Requests\MovingAfterService;
use Modules\Moving\Services\Requests\MovingCancellationService;
use Modules\Moving\Services\Requests\MovingRequestService;
use Modules\Provider\Http\Resources\App\VehicleTypeResource;
use Modules\Provider\Models\VehicleType;
use Modules\Rides\Services\Geo\GeoProvider;
use Modules\User\Models\User;
use Modules\Wallet\Services\CheckoutService;

/**
 * The customer's moves in the Dorr app (B-02 → B-06, REQ-081): draft, photos, publish, the offers three at a time,
 * and accepting one on the app's payment screen.
 */
class MovingRequestController extends Controller
{
    /** Offers are shown three at a time with "see more" (REQ-096, owner's call). */
    private const OFFERS_PAGE = 3;

    public function __construct(private readonly MovingRequestService $requests) {}

    public function options(MovingPolicies $policies): JsonResponse
    {
        $country = currentCountry();
        $serviceId = MovingRequestService::service()->id;

        return ApiResponse::success([
            // Off in this country: the app says so on opening instead of after the first step (REQ-001).
            'available' => $this->requests->isServiceOpen($country),
            'load_types' => array_column(LoadType::cases(), 'value'),
            'vehicle_types' => VehicleTypeResource::collection(
                VehicleType::query()->with('translations')->where('status', true)
                    ->whereHas('countries', fn ($q) => $q->whereKey($country->id))
                    ->whereHas('services', fn ($q) => $q->whereKey($serviceId))
                    ->orderBy('sort_order')->get()
            ),
            'media_max_mb' => $policies->for($country->id)['values']['media.max_mb'],
        ], __('api.retrieved'));
    }

    /**
     * The road between the two ends while the customer sets them: kilometres, minutes and the line the map draws —
     * through the map engine the admin chose for rides (Google or Mapbox), the straight-line estimate when it can't
     * answer. Asked once per change of an end, never on a timer; the same pair is kept for a day.
     */
    public function route(Request $request, GeoProvider $geo): JsonResponse
    {
        $data = $request->validate([
            'from_lat' => ['required', 'numeric', 'between:-90,90'],
            'from_lng' => ['required', 'numeric', 'between:-180,180'],
            'to_lat' => ['required', 'numeric', 'between:-90,90'],
            'to_lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $points = array_map(fn ($v) => round((float) $v, 4), $data);
        $key = 'moving:route:'.implode(',', $points);
        $route = Cache::remember($key, now()->addDay(), function () use ($geo, $points): array {
            $found = $geo->route($points['from_lat'], $points['from_lng'], $points['to_lat'], $points['to_lng']);

            return [
                'distance_m' => $found?->distanceM,
                'duration_s' => $found?->durationS,
                'polyline' => $found?->polyline,
                'estimated' => $found?->estimated ?? true,
            ];
        });

        return ApiResponse::success($route, __('api.retrieved'));
    }

    public function index(Request $request): JsonResponse
    {
        $rows = MovingRequest::query()->where('user_id', $this->user($request)->id)
            ->with(['vehicleType.translations', 'establishment'])
            ->when($request->query('status') === 'active', fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled', 'withdrawn', 'expired']))
            ->orderByDesc('id')->paginate(20);

        return ApiResponse::fromPaginator($rows, MovingRequestResource::collection($rows->items()), __('api.retrieved'));
    }

    public function store(MovingRequestRequest $request): JsonResponse
    {
        $move = $this->requests->create($this->user($request), currentCountry(), $request->validated());

        return ApiResponse::created(new MovingRequestResource($move), __('moving.draft_saved'));
    }

    public function show(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);

        return ApiResponse::success([
            'request' => new MovingRequestResource($move->load(['vehicleType.translations', 'establishment', 'vehicle', 'driver.provider'])),
            'offers_count' => $move->offers()->where('status', OfferStatus::Sent->value)->count(),
            'missing' => $move->status === MovingStatus::Draft ? $this->requests->missing($move) : [],
            // Given at the door to the driver (or shown as a QR) to confirm delivery; only the customer sees it.
            'delivery_code' => $move->status->isActiveJob() ? $move->delivery_code : null,
            'delivery_qr' => $move->status->isActiveJob() && $move->delivery_code ? "dorr://moving/{$move->uuid}/{$move->delivery_code}" : null,
            // What cancelling now would cost (announced before, REQ-043).
            'cancel_fee_minor' => $move->status->isActiveJob() ? app(MovingCancellationService::class)->customerFee($move) : null,
            'driver_location' => $move->driver_location_at ? ['lat' => $move->driver_lat, 'lng' => $move->driver_lng, 'at' => $move->driver_location_at->toIso8601String()] : null,
            'objection_until' => $move->objection_until?->toIso8601String(),
            'rated' => MovingRating::query()->where('request_id', $move->id)->exists(),
            // What the rating card asks the customer to score the establishment on.
            'rating_criteria' => MovingAfterService::criteria(),
            'inventory' => MovingInventoryResource::collection($move->inventory),
            'cases' => $move->cases()->latest('id')->get()->map(fn ($c) => ['uuid' => $c->uuid, 'kind' => $c->kind, 'status' => $c->status, 'decision' => $c->decision, 'award_minor' => $c->award_minor]),
        ], __('api.retrieved'));
    }

    public function update(MovingRequestRequest $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);

        return ApiResponse::success(new MovingRequestResource($this->requests->update($move, $request->validated())), __('moving.draft_saved'));
    }

    public function addMedia(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);
        $voice = $request->input('kind') === 'voice';
        $request->validate([
            'file' => ['required', 'file', $voice
                // A voice note about the load (owner, 2026-11-12): an m4a from the phone reads as audio or as mp4.
                ? 'mimetypes:audio/mp4,audio/x-m4a,audio/m4a,audio/aac,audio/mpeg,audio/ogg,audio/webm,audio/wav,audio/x-wav,video/mp4,application/octet-stream'
                : 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime'],
            'kind' => ['nullable', 'in:voice'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'waveform' => ['nullable'],
        ]);
        $media = $this->requests->addMedia($move, $request->file('file'), $voice ? [
            'duration_ms' => $request->integer('duration_ms') ?: null,
            'waveform' => $this->waveform($request->input('waveform')),
        ] : null);

        return ApiResponse::created([
            'id' => $media->id, 'url' => $media->getUrl(), 'collection' => $media->collection_name,
            'duration_ms' => $media->getCustomProperty('duration_ms'), 'waveform' => $media->getCustomProperty('waveform'),
        ], __('api.created'));
    }

    /**
     * The voice note's bars (0–100), sent as a list or as its JSON text; at most 64 of them.
     *
     * @return list<int>
     */
    private function waveform(mixed $value): array
    {
        $bars = is_string($value) ? json_decode($value, true) : $value;

        return array_slice(array_map(fn ($v) => max(0, min(100, (int) $v)), array_values(is_array($bars) ? $bars : [])), 0, 64);
    }

    public function removeMedia(Request $request, MovingRequest $move, int $media): JsonResponse
    {
        $this->assertMine($request, $move);
        $this->requests->removeMedia($move, $media);

        return ApiResponse::noContent(__('api.deleted'));
    }

    public function publish(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);

        return ApiResponse::success(new MovingRequestResource($this->requests->publish($move)), __('moving.published'));
    }

    /** An expired or withdrawn request comes back as a new draft with the same details (REQ-020). */
    public function republish(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);

        return ApiResponse::created(new MovingRequestResource($this->requests->republish($move)), __('moving.draft_saved'));
    }

    public function withdraw(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);

        return ApiResponse::success(new MovingRequestResource($this->requests->withdraw($move)), __('moving.withdrawn'));
    }

    /**
     * The live offers, best first, three per page. The order is explained, never a choice made for the customer
     * (REQ-023): the cheapest, the earliest arrival, and a vehicle of the type asked for are said as such.
     */
    public function offers(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);
        $offers = $move->offers()->with(['establishment', 'branch', 'vehicle.type.translations'])
            ->where('status', OfferStatus::Sent->value)->where('valid_until', '>', now())
            ->orderBy('price_minor')->orderBy('arrival_at')->get();

        $cheapest = $offers->min('price_minor');
        $earliest = $offers->min(fn (MovingOffer $o) => $o->arrival_at->timestamp);
        $page = max(1, (int) $request->query('page', 1));
        $slice = $offers->slice(($page - 1) * self::OFFERS_PAGE, self::OFFERS_PAGE)->values();

        return ApiResponse::success([
            'total' => $offers->count(),
            'page' => $page,
            'has_more' => $offers->count() > $page * self::OFFERS_PAGE,
            'offers' => $slice->map(function (MovingOffer $o) use ($cheapest, $earliest, $move) {
                $resource = new MovingOfferResource($o);
                $resource->reasons = array_values(array_filter([
                    $o->price_minor === $cheapest ? 'cheapest' : null,
                    $o->arrival_at->timestamp === $earliest ? 'earliest' : null,
                    $move->vehicle_type_id !== null && $o->vehicle?->vehicle_type_id === $move->vehicle_type_id ? 'vehicle_fit' : null,
                ]));

                return $resource;
            }),
        ], __('api.retrieved'));
    }

    /** Opens the payment screen for this offer; paying holds the money and accepts it (MovingCheckoutPurpose). */
    public function accept(Request $request, MovingRequest $move, MovingOffer $offer, CheckoutService $checkouts): JsonResponse
    {
        $this->assertMine($request, $move);
        if ($offer->request_id !== $move->id) {
            throw new MovingException('offer_not_available', 404);
        }
        $user = $this->user($request);
        $checkout = $checkouts->create($user, currentCountry(), MovingCheckoutPurpose::KEY, ['request_uuid' => $move->uuid, 'offer_uuid' => $offer->uuid]);

        return ApiResponse::created($checkouts->present($user, $checkout), __('api.created'));
    }

    /** The fee table in force for this request, shown on the payment screen before accepting (REQ-043, REQ-115). */
    public function cancellationTerms(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);

        return ApiResponse::success([
            'near_hours' => $move->policy('cancel.near_hours'),
            'early_bp' => $move->policy('cancel.customer.early_bp'),
            'near_bp' => $move->policy('cancel.customer.near_bp'),
            'on_way_bp' => $move->policy('cancel.customer.on_way_bp'),
            'arrived_bp' => $move->policy('cancel.customer.arrived_bp'),
            'reasons' => (array) config('moving.cancel_reasons.user', []),
        ], __('api.retrieved'));
    }

    public function cancel(Request $request, MovingRequest $move, MovingCancellationService $cancellations): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:40'], 'details' => ['nullable', 'string', 'max:1000']]);
        $result = $cancellations->byCustomer($this->user($request), $move, $data['reason'], $data['details'] ?? null);

        return $result instanceof MovingCase
            ? ApiResponse::created(['case' => $result->uuid, 'kind' => $result->kind], __('moving.exception_requested'))
            : ApiResponse::success(new MovingRequestResource($result), __('moving.cancelled'));
    }

    public function confirmReceipt(Request $request, MovingRequest $move, MovingSettlement $settlement): JsonResponse
    {
        return ApiResponse::success(new MovingRequestResource($settlement->confirmReceipt($this->user($request), $move)), __('moving.receipt_confirmed'));
    }

    public function report(Request $request, MovingRequest $move, MovingSettlement $settlement): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'in:'.implode(',', (array) config('moving.report_reasons', []))],
            'details' => ['nullable', 'string', 'max:2000'],
            'claim_minor' => ['required', 'integer', 'min:1'],
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['image', 'max:10240'],
        ]);
        $case = $settlement->report($this->user($request), $move, $data['reason'], $data['details'] ?? null, (int) $data['claim_minor'], $request->file('photos'));

        return ApiResponse::created(['case' => $case->uuid, 'status' => $case->status], __('moving.reported'));
    }

    private function assertMine(Request $request, MovingRequest $move): void
    {
        if ($move->user_id !== $this->user($request)->id) {
            throw new MovingException('request_not_found', 404);
        }
    }

    private function user(Request $request): User
    {
        return $request->user('user_api');
    }
}
