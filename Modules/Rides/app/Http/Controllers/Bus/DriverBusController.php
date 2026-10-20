<?php

namespace Modules\Rides\Http\Controllers\Bus;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\BusTrip;
use Modules\Rides\Services\Bus\BusService;

/** The captain's buses (O-20, O-21, O-24): his bus and its seat labels, his trips, who booked, boarding. */
class DriverBusController extends Controller
{
    public function __construct(private readonly BusService $bus) {}

    /** My bus, and a QR label for each seat (its number, printed and stuck on the seat). */
    public function vehicle(Request $request): JsonResponse
    {
        $bus = $this->bus->busOf($this->driver($request));
        $bus->loadMissing(['make.translations', 'model.translations']);

        return ApiResponse::success([
            'uuid' => $bus->uuid,
            'name' => trim(($bus->make?->translated('name') ?? '').' '.($bus->model?->translated('name') ?? '')),
            'plate' => trim($bus->plate_letters.' '.$bus->plate_number),
            'seats' => (int) $bus->seats,
            'seat_labels' => array_map(fn (int $n) => ['seat' => $n, 'qr' => 'DORR-BUS:'.$bus->uuid.':'.$n], range(1, max(1, (int) $bus->seats))),
        ], __('api.retrieved'));
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->bus->mine($this->driver($request))->map(fn (BusTrip $t) => BusPresenter::trip($t))->values(), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $trip = $this->bus->publish($this->driver($request), $this->validated($request, true));

        return ApiResponse::success(BusPresenter::trip($trip, null, true), __('rides.messages.bus_published'), 201);
    }

    /** The trip with its seat map and its passengers (O-24). */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $trip = $this->own($request, $uuid);

        return ApiResponse::success(BusPresenter::trip($trip, null, true) + ['passengers' => BusPresenter::passengers($trip)], __('api.retrieved'));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $trip = $this->bus->update($this->driver($request), $this->own($request, $uuid), $this->validated($request, false));

        return ApiResponse::success(BusPresenter::trip($trip, null, true), __('api.updated'));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $trip = $this->bus->cancelTrip($this->driver($request), $this->own($request, $uuid));

        return ApiResponse::success(BusPresenter::trip($trip), __('rides.messages.bus_trip_canceled'));
    }

    /** departed → returning → completed (then paid). */
    public function move(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['to' => ['required', Rule::in(['departed', 'returning', 'completed'])]]);
        $trip = $this->bus->move($this->driver($request), $this->own($request, $uuid), $data['to']);

        return ApiResponse::success(BusPresenter::trip($trip, null, true) + ['passengers' => BusPresenter::passengers($trip)], __('api.updated'));
    }

    /** Boarding by the ticket's QR, or by the seat on the passengers list. */
    public function board(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'qr' => ['nullable', 'string', 'max:48', 'required_without:seat'],
            'leg' => ['nullable', Rule::in(['outbound', 'return']), 'required_with:seat'],
            'seat' => ['nullable', 'integer', 'min:1'],
        ]);
        $trip = $this->own($request, $uuid);
        $seat = $this->bus->board($this->driver($request), $trip, $data['qr'] ?? null, $data['leg'] ?? null, isset($data['seat']) ? (int) $data['seat'] : null);

        return ApiResponse::success([
            'leg' => $seat->leg, 'seat' => $seat->seat_no, 'name' => $seat->booking?->user?->name,
            'passengers' => BusPresenter::passengers($trip),
        ], __('rides.messages.bus_boarded'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $need = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'origin' => [$need, 'array'],
            'origin.lat' => ['required_with:origin', 'numeric', 'between:-90,90'],
            'origin.lng' => ['required_with:origin', 'numeric', 'between:-180,180'],
            'origin.address' => ['required_with:origin', 'string', 'max:255'],
            'destination' => [$need, 'array'],
            'destination.lat' => ['required_with:destination', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required_with:destination', 'numeric', 'between:-180,180'],
            'destination.address' => ['required_with:destination', 'string', 'max:255'],
            'depart_at' => [$need, 'date'],
            'return_at' => ['nullable', 'date'],
            'price_outbound_minor' => [$need, 'integer', 'min:1', 'max:100000000'],
            'price_return_minor' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'price_round_minor' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'seats_total' => ['nullable', 'integer', 'min:1', 'max:120'],
            'seats_per_row' => ['nullable', 'integer', 'between:2,6'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function own(Request $request, string $uuid): BusTrip
    {
        return BusTrip::query()->where('uuid', $uuid)->where('driver_id', $this->driver($request)->id)->first()
            ?? throw new RidesException('bus_trip_not_found', 404);
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
