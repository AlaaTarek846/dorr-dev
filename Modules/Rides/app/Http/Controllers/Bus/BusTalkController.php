<?php

namespace Modules\Rides\Http\Controllers\Bus;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\BusBooking;
use Modules\Rides\Services\Bus\BusTalkService;

/**
 * O-68: a ticket's chat and in-app calls — for the passenger (`mobile/v1/rides/bus/bookings/{uuid}/…`) and
 * the captain of its trip (`driver/v1/bus/bookings/{uuid}/…`).
 */
class BusTalkController extends Controller
{
    public function __construct(private readonly BusTalkService $talk) {}

    public function messages(Request $request, string $uuid): JsonResponse
    {
        $booking = $this->booking($request, $uuid);
        $side = $this->side($request);

        return ApiResponse::success([
            'open' => $this->talk->isOpen($booking),
            'messages' => $this->talk->messages($booking, $side)->values(),
            'quick_replies' => BusTalkService::quickReplies($side),
            'call' => $this->talk->currentCall($booking),
        ], __('api.retrieved'));
    }

    public function send(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:1000', 'required_without:quick'],
            'quick' => ['nullable', 'string', 'max:32'],
            'client_id' => ['nullable', 'string', 'max:64'],
        ]);

        return ApiResponse::success(
            $this->talk->send($this->booking($request, $uuid), $this->side($request), $data['body'] ?? null, $data['quick'] ?? null, $data['client_id'] ?? null),
            __('api.created'), 201,
        );
    }

    public function startCall(Request $request, string $uuid): JsonResponse
    {
        ['call' => $call, 'join' => $join] = $this->talk->startCall($this->booking($request, $uuid), $this->side($request));

        return ApiResponse::success(BusTalkService::presentCall($call) + ['join' => $join], __('api.created'), 201);
    }

    public function acceptCall(Request $request, string $uuid, string $call): JsonResponse
    {
        $booking = $this->booking($request, $uuid);
        ['call' => $row, 'join' => $join] = $this->talk->accept($booking, $this->talk->call($booking, $call), $this->side($request));

        return ApiResponse::success(BusTalkService::presentCall($row) + ['join' => $join], __('api.updated'));
    }

    /** Decline a ring, cancel my own, or hang up. */
    public function endCall(Request $request, string $uuid, string $call): JsonResponse
    {
        $booking = $this->booking($request, $uuid);

        return ApiResponse::success(BusTalkService::presentCall($this->talk->end($booking, $this->talk->call($booking, $call), $this->side($request))), __('api.updated'));
    }

    private function booking(Request $request, string $uuid): BusBooking
    {
        $me = $request->user();
        $booking = BusBooking::query()->where('uuid', $uuid)
            ->when($me instanceof Driver, fn ($q) => $q->whereHas('trip', fn ($t) => $t->where('driver_id', $me->getKey())), fn ($q) => $q->where('user_id', $me->getKey()))
            ->with(['trip.driver', 'user'])->first();

        return $booking ?? throw new RidesException('bus_ticket_not_found', 404);
    }

    private function side(Request $request): string
    {
        return $request->user() instanceof Driver ? 'driver' : 'user';
    }
}
