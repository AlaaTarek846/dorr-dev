<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\StoreSupportTicketRequest;
use Modules\User\Http\Resources\SupportTicketResource;
use Modules\User\Models\SupportTicket;
use Modules\User\Models\User;

class SupportTicketController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user('user_api');

        $query = $user->supportTickets()->latest('id');

        return ApiResponse::paginated($query, SupportTicketResource::class, __('api.retrieved'));
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');
        $data = $request->validated();

        $path = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('support-tickets/'.$user->getKey(), 'public');
        }

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->getKey(),
            'title' => $data['title'],
            'body' => $data['body'],
            'image_path' => $path,
            'status' => 'open',
        ]);

        return ApiResponse::success(
            new SupportTicketResource($ticket),
            __('api.support_ticket_created'),
            201,
        );
    }
}
