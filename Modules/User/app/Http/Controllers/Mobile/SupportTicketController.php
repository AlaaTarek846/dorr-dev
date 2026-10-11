<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Enums\SupportTicketStatus;
use Modules\User\Http\Requests\StoreSupportMessageRequest;
use Modules\User\Http\Requests\StoreSupportTicketRequest;
use Modules\User\Http\Requests\SupportTicketStatusRequest;
use Modules\User\Http\Resources\SupportMessageResource;
use Modules\User\Http\Resources\SupportTicketResource;
use Modules\User\Models\SupportTicket;
use Modules\User\Models\User;
use Modules\User\Services\SupportTicketService;

/**
 * The customer's side of support: their tickets, the conversation inside each, and closing or
 * reopening a ticket. Everything is scoped to the signed-in user: someone else's ticket is a 404.
 */
class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $service) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');
        $query = $user->supportTickets()->with('latestMessage')->latest('last_message_at')->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return ApiResponse::paginated($query, SupportTicketResource::class, __('api.retrieved'));
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        return ApiResponse::success(new SupportTicketResource($this->find($request, $ticket)->load('latestMessage')), __('api.retrieved'));
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');
        $data = $request->validated();

        $ticket = $this->service->open($user, $data['title'], $data['body'], $request->file('image'));

        return ApiResponse::success(new SupportTicketResource($ticket), __('api.support_ticket_created'), 201);
    }

    public function status(SupportTicketStatusRequest $request, int $ticket): JsonResponse
    {
        $moved = $this->service->customerSetStatus(
            $this->find($request, $ticket),
            SupportTicketStatus::from($request->validated('status')),
        );

        return ApiResponse::success(new SupportTicketResource($moved->load('latestMessage')), __('api.support_ticket_status_changed'));
    }

    public function messages(Request $request, int $ticket): JsonResponse
    {
        $found = $this->find($request, $ticket);

        // order=desc: the newest page first (a long conversation opens at its end; older pages follow).
        $direction = $request->query('order') === 'desc' ? 'desc' : 'asc';

        return ApiResponse::paginated($found->messages()->with('admin')->orderBy('id', $direction), SupportMessageResource::class, __('api.retrieved'));
    }

    public function sendMessage(StoreSupportMessageRequest $request, int $ticket): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');
        $message = $this->service->customerReply($user, $this->find($request, $ticket), $request->validated('body'), $request->file('image'));

        return $this->service->messageResponse($message, __('api.support_message_sent'));
    }

    /**
     * The customer's answer under an automatic FAQ reply: `solved: true` closes the ticket, `false` stops the
     * automatic replies and tells the support team a person is needed.
     */
    public function autoReplyFeedback(Request $request, int $ticket): JsonResponse
    {
        $solved = (bool) $request->validate(['solved' => ['required', 'boolean']])['solved'];
        $updated = $this->service->autoReplyFeedback($this->find($request, $ticket), $solved);

        return ApiResponse::success(new SupportTicketResource($updated->load('latestMessage')), __('api.support_ticket_status_changed'));
    }

    private function find(Request $request, int $id): SupportTicket
    {
        /** @var User $user */
        $user = $request->user('user_api');

        return $user->supportTickets()->findOrFail($id);
    }
}
