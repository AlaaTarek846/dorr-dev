<?php

namespace Modules\Moving\Http\Controllers\Provider;

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
use Modules\Moving\Services\Requests\MovingAfterService;
use Modules\Moving\Services\Requests\MovingChangeService;
use Modules\Provider\Services\Establishments\EstablishmentAccess;

/** The establishment's side of changes, new times, tickets and the invoice (B-13, B-15, B-06). */
class MovingProviderAfterController extends Controller
{
    public function __construct(
        private readonly EstablishmentAccess $access,
        private readonly MovingChangeService $changes,
        private readonly MovingAfterService $after,
    ) {}

    /** A price difference for more load, trips or workers (jobs.change_price, or the job's own driver). */
    public function proposeChange(Request $request, MovingRequest $move): JsonResponse
    {
        $data = $request->validate([
            'price_delta_minor' => ['required', 'integer', 'not_in:0'],
            'workers_count' => ['nullable', 'integer', 'between:0,50'],
            'trips_count' => ['nullable', 'integer', 'between:1,50'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $change = $this->changes->proposeChange($this->access->current($request), $move, $data);

        return ApiResponse::created(['uuid' => $change->uuid, 'status' => $change->status], __('moving.change_requested'));
    }

    public function reschedule(Request $request, MovingRequest $move): JsonResponse
    {
        $actor = $this->access->current($request);
        if ($move->establishment_id !== $actor->establishment_id) {
            throw new MovingException('request_not_found', 404);
        }
        $this->access->authorize($actor, 'jobs.assign', $move->branch);
        $data = $request->validate(['scheduled_at' => ['required', 'date', 'after:now'], 'reason' => ['required', 'string', 'max:500']]);
        $change = $this->changes->proposeReschedule('provider', $actor->provider_id, $move, Carbon::parse($data['scheduled_at']), $data['reason']);

        return ApiResponse::created(['uuid' => $change->uuid, 'status' => $change->status], __('moving.change_requested'));
    }

    /** The answer to the customer's new time. */
    public function respond(Request $request, MovingChangeOrder $change): JsonResponse
    {
        $approve = $request->validate(['approve' => ['required', 'boolean']])['approve'];
        $change = $this->changes->respond($change, (bool) $approve, null, $this->access->current($request));

        return ApiResponse::success(['uuid' => $change->uuid, 'status' => $change->status], __('moving.change_'.$change->status));
    }

    public function invoice(Request $request, MovingRequest $move): JsonResponse
    {
        $actor = $this->access->current($request);
        if ($move->establishment_id !== $actor->establishment_id) {
            throw new MovingException('request_not_found', 404);
        }

        $this->access->authorize($actor, 'jobs.track', $move->branch);

        return ApiResponse::success($this->after->invoice($move->load(['acceptedOffer', 'establishment']), true), __('api.retrieved'));
    }

    public function tickets(Request $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $this->access->authorize($actor, 'tickets.handle');
        $rows = MovingTicket::query()->with('request')->where('establishment_id', $actor->establishment_id)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate(20);

        return ApiResponse::fromPaginator($rows, MovingTicketResource::collection($rows->items()), __('api.retrieved'));
    }

    public function showTicket(Request $request, MovingTicket $ticket): JsonResponse
    {
        $actor = $this->access->current($request);
        if ($ticket->establishment_id !== $actor->establishment_id) {
            throw new MovingException('ticket_not_found', 404);
        }
        $this->access->authorize($actor, 'tickets.handle');

        return ApiResponse::success(new MovingTicketResource($ticket->load(['messages', 'request'])), __('api.retrieved'));
    }

    public function reply(Request $request, MovingTicket $ticket): JsonResponse
    {
        $body = $request->validate(['body' => ['required', 'string', 'max:3000']])['body'];
        $ticket = $this->after->establishmentReply($this->access->current($request), $ticket, $body);

        return ApiResponse::success(new MovingTicketResource($ticket->load(['messages', 'request'])), __('api.updated'));
    }
}
