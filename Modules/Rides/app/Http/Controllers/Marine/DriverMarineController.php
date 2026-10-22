<?php

namespace Modules\Rides\Http\Controllers\Marine;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\MarineBooking;
use Modules\Rides\Models\MarineListing;
use Modules\Rides\Services\Marine\MarineService;

/** The captain's boat (O-25): his sea trips and yacht hires, who booked, boarding, more time, the end. */
class DriverMarineController extends Controller
{
    public function __construct(private readonly MarineService $marine) {}

    public function boat(Request $request): JsonResponse
    {
        $boat = $this->marine->boatOf($this->driver($request));
        $boat->loadMissing(['make.translations', 'model.translations']);

        return ApiResponse::success([
            'uuid' => $boat->uuid,
            'type' => $boat->body_type instanceof \BackedEnum ? $boat->body_type->value : $boat->body_type,
            'name' => trim(($boat->make?->translated('name') ?? '').' '.($boat->model?->translated('name') ?? '')),
            'seats' => (int) $boat->seats,
            'amenities' => MarineListing::AMENITIES,
        ], __('api.retrieved'));
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->marine->mine($this->driver($request))->map(fn (MarineListing $l) => MarinePresenter::listing($l))->values(), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['trip', 'rental'])],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'marina' => ['required', 'array'],
            'marina.lat' => ['required', 'numeric', 'between:-90,90'],
            'marina.lng' => ['required', 'numeric', 'between:-180,180'],
            'marina.address' => ['required', 'string', 'max:255'],
            'route_note' => ['nullable', 'string', 'max:255'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['string', Rule::in(MarineListing::AMENITIES)],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'depart_at' => ['required_if:kind,trip', 'nullable', 'date'],
            'duration_min' => ['required_if:kind,trip', 'nullable', 'integer', 'min:15', 'max:1440'],
            'seat_price_minor' => ['nullable', 'integer', 'min:1', 'max:100000000', 'required_without_all:private_price_minor,hour_price_minor'],
            'private_price_minor' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'hour_price_minor' => ['required_if:kind,rental', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'min_hours' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);
        $listing = $this->marine->publish($this->driver($request), $data);

        return ApiResponse::success(MarinePresenter::listing($listing, true), __('rides.messages.marine_published'), 201);
    }

    /** The listing with its bookings (who, how many, when, their phone). */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $listing = $this->own($request, $uuid);

        return ApiResponse::success(MarinePresenter::listing($listing, true) + ['bookings' => $this->bookingsOf($listing)], __('api.retrieved'));
    }

    /** paused / active / departed / completed (then paid) / canceled (all refunded). */
    public function move(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['to' => ['required', Rule::in(['paused', 'active', 'departed', 'completed', 'canceled'])]]);
        $listing = $this->marine->move($this->driver($request), $this->own($request, $uuid), $data['to']);

        return ApiResponse::success(MarinePresenter::listing($listing, true) + ['bookings' => $this->bookingsOf($listing)], __('api.updated'));
    }

    /** Boarding by the booking's QR. */
    public function board(Request $request): JsonResponse
    {
        $data = $request->validate(['qr' => ['required', 'string', 'max:48']]);
        $booking = $this->marine->board($this->driver($request), $data['qr']);

        return ApiResponse::success(MarinePresenter::booking($booking, false), __('rides.messages.marine_boarded'));
    }

    /** One booking is over (a hire): the captain is paid for it. */
    public function finish(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(MarinePresenter::booking($this->marine->finish($this->driver($request), $this->booking($uuid)), false), __('api.updated'));
    }

    /** R-42, D-102: yes or no to more time. */
    public function extension(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $booking = $this->marine->answerExtension($this->driver($request), $this->booking($uuid), (bool) $data['approve']);

        return ApiResponse::success(MarinePresenter::booking($booking, false), __('api.updated'));
    }

    /** @return list<array<string, mixed>> */
    private function bookingsOf(MarineListing $listing): array
    {
        return $listing->bookings()->with('user')->whereIn('status', ['confirmed', 'boarded', 'completed'])->orderBy('starts_at')->limit(200)->get()
            ->map(fn (MarineBooking $b) => MarinePresenter::booking($b, false))->values()->all();
    }

    private function own(Request $request, string $uuid): MarineListing
    {
        return MarineListing::query()->where('uuid', $uuid)->where('driver_id', $this->driver($request)->id)->first()
            ?? throw new RidesException('marine_not_found', 404);
    }

    private function booking(string $uuid): MarineBooking
    {
        return MarineBooking::query()->where('uuid', $uuid)->with('listing')->first() ?? throw new RidesException('marine_not_found', 404);
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
