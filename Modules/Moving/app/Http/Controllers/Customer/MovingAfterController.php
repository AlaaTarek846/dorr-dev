<?php

namespace Modules\Moving\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Http\Resources\MovingTicketResource;
use Modules\Moving\Models\MovingChangeOrder;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Models\MovingTicket;
use Modules\Moving\Services\Payment\MovingChangeCheckoutPurpose;
use Modules\Moving\Services\Requests\MovingAfterService;
use Modules\Moving\Services\Requests\MovingChangeService;
use Modules\User\Models\User;
use Modules\Wallet\Services\CheckoutService;

/**
 * The customer's side of changes, new times, rating, tickets and the invoice (B-13, B-15, B-06).
 */
class MovingAfterController extends Controller
{
    public function __construct(
        private readonly MovingChangeService $changes,
        private readonly MovingAfterService $after,
    ) {}

    public function changes(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);

        return ApiResponse::success(MovingChangeOrder::query()->where('request_id', $move->id)->orderByDesc('id')->get()
            ->map(fn (MovingChangeOrder $c) => $this->presentChange($c))->values(), __('api.retrieved'));
    }

    /** Yes or no to the establishment's change. A yes that costs more opens the payment screen for the difference. */
    public function respond(Request $request, MovingRequest $move, MovingChangeOrder $change, CheckoutService $checkouts): JsonResponse
    {
        $this->assertMine($request, $move);
        if ($change->request_id !== $move->id) {
            throw new MovingException('change_not_found', 404);
        }
        $approve = $request->validate(['approve' => ['required', 'boolean']])['approve'];
        $change = $this->changes->respond($change, (bool) $approve, $this->user($request));

        if ($change->status === 'awaiting_payment') {
            $checkout = $checkouts->create($this->user($request), currentCountry(), MovingChangeCheckoutPurpose::KEY, ['change_uuid' => $change->uuid]);

            return ApiResponse::created(['change' => $this->presentChange($change), 'checkout' => $checkouts->present($this->user($request), $checkout)], __('api.created'));
        }

        return ApiResponse::success(['change' => $this->presentChange($change)], __('moving.change_'.$change->status));
    }

    public function reschedule(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);
        $data = $request->validate(['scheduled_at' => ['required', 'date', 'after:now'], 'reason' => ['required', 'string', 'max:500']]);
        $change = $this->changes->proposeReschedule('user', $this->user($request)->id, $move, Carbon::parse($data['scheduled_at']), $data['reason']);

        return ApiResponse::created($this->presentChange($change), __('moving.change_requested'));
    }

    public function rate(Request $request, MovingRequest $move): JsonResponse
    {
        $data = $request->validate([
            'establishment_stars' => ['required', 'integer', 'between:1,5'],
            'driver_stars' => ['nullable', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->after->rate($this->user($request), $move, (int) $data['establishment_stars'], $data['driver_stars'] ?? null, $data['comment'] ?? null);

        return ApiResponse::created([], __('moving.rated'));
    }

    public function invoice(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);

        return ApiResponse::success($this->after->invoice($move->load(['acceptedOffer', 'establishment']), false), __('api.retrieved'));
    }

    public function tickets(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assertMine($request, $move);

        return ApiResponse::success(MovingTicketResource::collection(MovingTicket::query()->with('request')->where('request_id', $move->id)->orderByDesc('id')->get()), __('api.retrieved'));
    }

    public function openTicket(Request $request, MovingRequest $move): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', MovingTicket::TYPES)],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:3000'],
        ]);
        $ticket = $this->after->openTicket($this->user($request), $move, $data['type'], $data['subject'], $data['body']);

        return ApiResponse::created(new MovingTicketResource($ticket->load(['messages', 'request'])), __('moving.ticket_opened'));
    }

    public function showTicket(Request $request, MovingTicket $ticket): JsonResponse
    {
        if ($ticket->user_id !== $this->user($request)->id) {
            throw new MovingException('ticket_not_found', 404);
        }

        return ApiResponse::success(new MovingTicketResource($ticket->load(['messages', 'request'])), __('api.retrieved'));
    }

    public function actOnTicket(Request $request, MovingTicket $ticket): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:reply,resolve,escalate'], 'body' => ['nullable', 'string', 'max:3000']]);
        $ticket = $this->after->customerAct($this->user($request), $ticket, $data['action'], $data['body'] ?? null);

        return ApiResponse::success(new MovingTicketResource($ticket->load(['messages', 'request'])), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function presentChange(MovingChangeOrder $c): array
    {
        return [
            'uuid' => $c->uuid, 'kind' => $c->kind, 'proposed_by' => $c->proposed_by, 'status' => $c->status,
            'price_delta_minor' => $c->price_delta_minor, 'extra_total_minor' => $c->status === 'pending' || $c->status === 'awaiting_payment' ? $this->changes->extraTotal($c) : null,
            'workers_count' => $c->workers_count, 'trips_count' => $c->trips_count,
            'new_scheduled_at' => $c->new_scheduled_at?->toIso8601String(), 'reason' => $c->reason,
            'before' => $c->before, 'after' => $c->after, 'created_at' => $c->created_at?->toIso8601String(),
        ];
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
