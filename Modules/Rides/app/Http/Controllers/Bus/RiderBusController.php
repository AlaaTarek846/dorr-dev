<?php

namespace Modules\Rides\Http\Controllers\Bus;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\BusBooking;
use Modules\Rides\Models\BusTrip;
use Modules\Rides\Services\Bus\BusService;
use Modules\Rides\Services\Geo\CityResolver;
use Modules\User\Models\User;

/** The rider's bus booking (O-22, O-23): find a trip, see its seats, book them, the tickets. */
class RiderBusController extends Controller
{
    public function __construct(private readonly BusService $bus) {}

    /** By where I leave from, where I go, or both — and optionally the day. */
    public function search(Request $request, CityResolver $cities): JsonResponse
    {
        $data = $request->validate([
            'from_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:from_lng'],
            'from_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:from_lat'],
            'to_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:to_lng'],
            'to_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:to_lat'],
            'date' => ['nullable', 'date'],
        ]);
        $from = isset($data['from_lat']) ? ['lat' => (float) $data['from_lat'], 'lng' => (float) $data['from_lng']] : null;
        $to = isset($data['to_lat']) ? ['lat' => (float) $data['to_lat'], 'lng' => (float) $data['to_lng']] : null;
        $point = $from ?? $to;
        $city = $point === null ? null : $cities->cityAt($point['lat'], $point['lng']);
        $countryId = ($city !== null ? $cities->countryOf($city) : null) ?? $this->rider($request)->country_id;
        if ($countryId === null) {
            return ApiResponse::success([], __('api.retrieved'));
        }

        $found = $this->bus->search($from, $to, isset($data['date']) ? Carbon::parse($data['date']) : null, (int) $countryId);

        return ApiResponse::success(array_map(fn (array $f) => BusPresenter::trip($f['trip'], $f['leg']) + [
            'from_distance_m' => $f['from_m'], 'to_distance_m' => $f['to_m'],
        ], $found), __('api.retrieved'));
    }

    /** One trip with its seat map for both legs (taken seats). */
    public function show(string $uuid): JsonResponse
    {
        return ApiResponse::success(BusPresenter::trip($this->trip($uuid), null, true), __('api.retrieved'));
    }

    public function book(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'trip_type' => ['required', Rule::in(['outbound', 'return', 'round'])],
            'seats_outbound' => ['nullable', 'array', 'max:20'],
            'seats_outbound.*' => ['integer', 'min:1'],
            'seats_return' => ['nullable', 'array', 'max:20'],
            'seats_return.*' => ['integer', 'min:1'],
        ]);
        $booking = $this->bus->book(
            $this->rider($request), $this->trip($uuid), $data['trip_type'],
            $data['seats_outbound'] ?? [], $data['seats_return'] ?? [], $request->header('Idempotency-Key'),
        );

        return ApiResponse::success(BusPresenter::booking($booking), __('rides.messages.bus_booked'), 201);
    }

    public function bookings(Request $request): JsonResponse
    {
        $rows = BusBooking::query()->where('user_id', $this->rider($request)->id)->latest('id')->limit(50)->get();

        return ApiResponse::success($rows->map(fn (BusBooking $b) => BusPresenter::booking($b))->values(), __('api.retrieved'));
    }

    public function booking(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(BusPresenter::booking($this->ownBooking($request, $uuid)), __('api.retrieved'));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $booking = $this->bus->cancelBooking($this->rider($request), $this->ownBooking($request, $uuid));

        return ApiResponse::success(BusPresenter::booking($booking), __('rides.messages.bus_canceled'));
    }

    private function trip(string $uuid): BusTrip
    {
        return BusTrip::query()->where('uuid', $uuid)->first() ?? throw new RidesException('bus_trip_not_found', 404);
    }

    private function ownBooking(Request $request, string $uuid): BusBooking
    {
        return BusBooking::query()->where('uuid', $uuid)->where('user_id', $this->rider($request)->id)->first()
            ?? throw new RidesException('bus_ticket_not_found', 404);
    }

    private function rider(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
