<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Http\Requests\StoreSupportMessageRequest;
use Modules\User\Http\Resources\SupportMessageResource;
use Modules\User\Models\User;

class SupportChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');
        $query = $user->supportMessages()->orderBy('id');

        if ($request->filled('ticket_id')) {
            $ticketId = (int) $request->query('ticket_id');
            $user->supportTickets()->findOrFail($ticketId);
            $query->where('support_ticket_id', $ticketId);
        } else {
            $query->whereNull('support_ticket_id');
        }

        return ApiResponse::paginated($query, SupportMessageResource::class, __('api.retrieved'));
    }

    public function store(StoreSupportMessageRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');
        $data = $request->validated();
        $ticketId = isset($data['ticket_id']) ? (int) $data['ticket_id'] : null;

        if ($ticketId !== null) {
            $user->supportTickets()->findOrFail($ticketId);
        }

        $message = $user->supportMessages()->create([
            'support_ticket_id' => $ticketId,
            'sender' => 'user',
            'body' => $data['body'],
        ]);

        return ApiResponse::success(
            new SupportMessageResource($message),
            __('api.support_message_sent'),
            201,
        );
    }
}
