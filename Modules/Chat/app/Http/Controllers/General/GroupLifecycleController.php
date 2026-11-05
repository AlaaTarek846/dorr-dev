<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\GroupLifecycleService;

/**
 * Educational / work groups: answering a hand-over (GR-005), archive, and bringing a deleted
 * group back (GR-006). The offer itself is `POST groups/{c}/owner`, as for any group.
 */
class GroupLifecycleController extends Controller
{
    public function __construct(
        private readonly GroupLifecycleService $lifecycle,
        private readonly ConversationService $conversations,
    ) {}

    /** POST groups/{c}/owner/accept — the member it was offered to takes the group. */
    public function accept(Request $request, ChatConversation $conversation)
    {
        $me = $request->user();
        $this->lifecycle->acceptOwnership($me, $conversation);

        return ApiResponse::success($this->conversations->show($me, $conversation->refresh()), __('api.updated'));
    }

    /** POST groups/{c}/owner/decline */
    public function decline(Request $request, ChatConversation $conversation)
    {
        $me = $request->user();
        $this->lifecycle->declineOwnership($me, $conversation);

        return ApiResponse::success($this->conversations->show($me, $conversation->refresh()), __('api.updated'));
    }

    /** DELETE groups/{c}/owner — the owner takes the offer back. */
    public function cancel(Request $request, ChatConversation $conversation)
    {
        $me = $request->user();
        $this->lifecycle->cancelOwnership($me, $conversation);

        return ApiResponse::success($this->conversations->show($me, $conversation->refresh()), __('api.updated'));
    }

    /** POST groups/{c}/archive `{archived: bool}` — the owner; read only while archived. */
    public function archive(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['archived' => ['required', 'boolean']]);
        $me = $request->user();
        $this->lifecycle->archive($me, $conversation, (bool) $data['archived']);

        return ApiResponse::success($this->conversations->show($me, $conversation->refresh()), __('api.updated'));
    }

    /** GET groups/deleted — my deleted groups that can still come back. */
    public function deleted(Request $request)
    {
        return ApiResponse::success($this->lifecycle->deletedOwnedBy($request->user()), __('api.retrieved'));
    }

    /** POST groups/{c}/restore — before `purge_after`. */
    public function restore(Request $request, ChatConversation $conversation)
    {
        $me = $request->user();
        $this->lifecycle->restore($me, $conversation);

        return ApiResponse::success($this->conversations->show($me, $conversation->refresh()), __('api.updated'));
    }
}
