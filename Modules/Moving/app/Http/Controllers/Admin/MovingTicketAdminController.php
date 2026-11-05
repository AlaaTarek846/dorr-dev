<?php

namespace Modules\Moving\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Moving\Http\Resources\MovingTicketResource;
use Modules\Moving\Models\MovingTicket;
use Modules\Moving\Services\Requests\MovingAfterService;

/** Admin → Moving → Tickets (B-15): the tickets taken to Dorr, answered and closed with a reason (REQ-063, REQ-064). */
class MovingTicketAdminController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('moving-tickets', [
            ['view', ['index', 'show']],
            ['update', ['act']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = MovingTicket::query()->with('request')
            ->when($request->query('status', 'escalated'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('escalated_at')->orderByDesc('id')->paginate(20);

        return ApiResponse::fromPaginator($rows, MovingTicketResource::collection($rows->items()), __('api.retrieved'));
    }

    public function show(MovingTicket $ticket): JsonResponse
    {
        return ApiResponse::success(new MovingTicketResource($ticket->load(['messages', 'request'])), __('api.retrieved'));
    }

    public function act(Request $request, MovingTicket $ticket, MovingAfterService $after, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:3000'],
            'close' => ['nullable', 'in:resolved,unresolved'],
            'reason' => ['required_with:close', 'nullable', 'string', 'max:500'],
        ]);
        $ticket = $after->adminAct($request->user('admin_api')->id, $ticket, $data['body'] ?? null, $data['close'] ?? null, $data['reason'] ?? null);
        $audit->record('moving_ticket.acted', $ticket, null, $data);

        return ApiResponse::success(new MovingTicketResource($ticket->load(['messages', 'request'])), __('api.updated'));
    }
}
