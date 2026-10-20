<?php

namespace Modules\Rides\Http\Controllers\Marine;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\MarineBooking;
use Modules\Rides\Models\MarineListing;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\Rides\Services\Marine\MarineService;
use Modules\User\Models\User;

/** The guest's boats & yachts (O-25): sea trips and yachts for hire, book, my bookings, more time. */
class RiderMarineController extends Controller
{
    public function __construct(private readonly MarineService $marine) {}

    public function index(Request $request, CityResolver $cities): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(['trip', 'rental'])],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);
        $near = isset($data['lat']) ? ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']] : null;
        $city = $near === null ? null : $cities->cityAt($near['lat'], $near['lng']);
        $countryId = ($city !== null ? $cities->countryOf($city) : null) ?? $this->guest($request)->country_id;
        if ($countryId === null) {
            return ApiResponse::success([], __('api.retrieved'));
        }

        return ApiResponse::success(
            $this->marine->browse((int) $countryId, $data['kind'] ?? null, $near)->map(fn (MarineListing $l) => MarinePresenter::listing($l))->values(),
            __('api.retrieved'),
        );
    }

    public function show(string $uuid): JsonResponse
    {
        return ApiResponse::success(MarinePresenter::listing($this->listing($uuid), true), __('api.retrieved'));
    }

    public function book(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['shared', 'private', 'rental'])],
            'guests' => ['required', 'integer', 'min:1', 'max:500'],
            'starts_at' => ['nullable', 'date', 'required_if:mode,rental'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:72', 'required_if:mode,rental'],
        ]);
        $booking = $this->marine->book(
            $this->guest($request), $this->listing($uuid), $data['mode'], (int) $data['guests'],
            isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : null, isset($data['hours']) ? (int) $data['hours'] : null,
            $request->header('Idempotency-Key'),
        );

        return ApiResponse::success(MarinePresenter::booking($booking), __('rides.messages.marine_booked'), 201);
    }

    public function bookings(Request $request): JsonResponse
    {
        $rows = MarineBooking::query()->where('user_id', $this->guest($request)->id)->latest('id')->limit(50)->get();

        return ApiResponse::success($rows->map(fn (MarineBooking $b) => MarinePresenter::booking($b))->values(), __('api.retrieved'));
    }

    public function booking(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(MarinePresenter::booking($this->own($request, $uuid)), __('api.retrieved'));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(MarinePresenter::booking($this->marine->cancel($this->guest($request), $this->own($request, $uuid))), __('rides.messages.marine_canceled'));
    }

    /** R-42: more hours on a hire — the captain is asked. */
    public function extend(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:24']]);
        $booking = $this->marine->askExtension($this->guest($request), $this->own($request, $uuid), (int) $data['hours']);

        return ApiResponse::success(MarinePresenter::booking($booking), __('rides.messages.marine_extension_sent'));
    }

    private function listing(string $uuid): MarineListing
    {
        return MarineListing::query()->where('uuid', $uuid)->first() ?? throw new RidesException('marine_not_found', 404);
    }

    private function own(Request $request, string $uuid): MarineBooking
    {
        return MarineBooking::query()->where('uuid', $uuid)->where('user_id', $this->guest($request)->id)->first()
            ?? throw new RidesException('marine_not_found', 404);
    }

    private function guest(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
