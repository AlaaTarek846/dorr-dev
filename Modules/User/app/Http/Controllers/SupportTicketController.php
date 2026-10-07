<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Admin\Models\Admin;
use Modules\User\Enums\SupportTicketStatus;
use Modules\User\Http\Requests\StoreSupportMessageRequest;
use Modules\User\Http\Requests\SupportTicketStatusRequest;
use Modules\User\Http\Resources\AdminSupportTicketResource;
use Modules\User\Http\Resources\SupportMessageResource;
use Modules\User\Models\SupportTicket;
use Modules\User\Services\SupportTicketService;

/**
 * The dashboard side of support: every customer ticket, its conversation, answering it and moving
 * its status. Live: the customer's app and every open dashboard update as it happens.
 */
class SupportTicketController extends Controller implements HasMiddleware
{
    public function __construct(private readonly SupportTicketService $service) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('support-tickets', [
            ['view', ['index', 'show', 'messages', 'activities']],
            ['reply', ['sendMessage']],
            ['change-status', ['status']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->with(['user', 'admin', 'latestMessage']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('mine')) {
            $query->where('admin_id', $request->user('admin_api')?->getKey());
        }

        if (filled($term = trim((string) $request->query('search', '')))) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $query->where(function ($q) use ($like, $term) {
                $q->where('title', 'like', $like)
                    ->orWhere('id', ctype_digit(ltrim($term, '#')) ? (int) ltrim($term, '#') : 0)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('phone', 'like', $like));
            });
        }

        $query->latest('last_message_at')->latest('id');

        return ApiResponse::paginated($query, AdminSupportTicketResource::class, __('api.retrieved'));
    }

    public function show(SupportTicket $supportTicket): JsonResponse
    {
        return ApiResponse::success(new AdminSupportTicketResource($supportTicket->load(['user', 'admin', 'latestMessage'])), __('api.retrieved'));
    }

    public function messages(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        // order=desc: the newest page first (a long conversation opens at its end; older pages follow).
        $direction = $request->query('order') === 'desc' ? 'desc' : 'asc';

        return ApiResponse::paginated($supportTicket->messages()->with('admin')->orderBy('id', $direction), SupportMessageResource::class, __('api.retrieved'));
    }

    public function activities(SupportTicket $supportTicket): JsonResponse
    {
        $rows = $supportTicket->activities()->with('admin')->orderBy('id')->get()->map(fn ($a) => [
            'id' => $a->id,
            'status' => $a->status->value,
            'actor' => $a->actor,
            'admin' => $a->admin?->name,
            'created_at' => $a->created_at?->toIso8601String(),
        ]);

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function sendMessage(StoreSupportMessageRequest $request, SupportTicket $supportTicket): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin_api');
        $message = $this->service->agentReply($admin, $supportTicket, $request->validated('body'), $request->file('image'));

        return $this->service->messageResponse($message, __('api.support_message_sent'));
    }

    public function status(SupportTicketStatusRequest $request, SupportTicket $supportTicket): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin_api');
        $moved = $this->service->agentSetStatus($admin, $supportTicket, SupportTicketStatus::from($request->validated('status')));

        return ApiResponse::success(new AdminSupportTicketResource($moved->load(['user', 'admin', 'latestMessage'])), __('api.support_ticket_status_changed'));
    }
}
