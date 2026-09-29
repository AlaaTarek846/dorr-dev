<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Http\Requests\ConversationSettingsRequest;
use Modules\Chat\Http\Resources\ConversationResource;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\MessageService;
use Modules\Chat\Support\ParticipantType;

/**
 * The chat list and everything about a conversation as a whole (docs/chat-plan.md §3.1–3.5).
 */
class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'unread', 'groups', 'direct', 'archived', 'locked', 'requests'])],
            'folder' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $me = $request->user();
        $page = $this->conversations->list($me, $filters, (int) ($filters['per_page'] ?? 20));
        $data = $page->getCollection()->map(fn (ChatParticipant $p) => new ConversationResource($p, $me))->values();

        return ApiResponse::success($data, __('api.retrieved'), 200, ApiPaginator::meta($page), [
            'requests_count' => $this->conversations->requestsCount($me),
        ]);
    }

    /**
     * Open the direct chat with someone (created on first use, shown in lists after the first message).
     */
    public function direct(Request $request)
    {
        $data = $request->validate([
            'participant_type' => ['nullable', 'string'],
            'participant_id' => ['required', 'integer'],
        ]);

        $alias = $data['participant_type'] ?? 'user';
        if (! ParticipantType::isEnabled($alias)) {
            throw ChatException::participantDisabled();
        }

        $other = ParticipantType::modelClassFor($alias)::query()->find($data['participant_id']) ?? throw ChatException::userNotFound();
        $me = $request->user();

        return ApiResponse::success($this->conversations->resource($me, $this->conversations->openDirect($me, $other)), __('api.retrieved'));
    }

    public function show(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->conversations->show($request->user(), $conversation), __('api.retrieved'));
    }

    public function accept(Request $request, ChatConversation $conversation)
    {
        $me = $request->user();

        return ApiResponse::success($this->conversations->resource($me, $this->conversations->accept($me, $conversation)), __('api.updated'));
    }

    public function reject(Request $request, ChatConversation $conversation)
    {
        $this->conversations->reject($request->user(), $conversation, $request->boolean('block'));

        return ApiResponse::success(null, __('api.updated'));
    }

    public function settings(ConversationSettingsRequest $request, ChatConversation $conversation)
    {
        $me = $request->user();

        return ApiResponse::success($this->conversations->resource($me, $this->conversations->updateSettings($me, $conversation, $request->validated())), __('api.updated'));
    }

    public function clear(Request $request, ChatConversation $conversation)
    {
        $me = $request->user();

        return ApiResponse::success($this->conversations->resource($me, $this->conversations->clear($me, $conversation)), __('api.updated'));
    }

    public function destroy(Request $request, ChatConversation $conversation)
    {
        $this->conversations->deleteForMe($request->user(), $conversation);

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function read(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['up_to' => ['nullable', 'uuid']]);
        $upTo = isset($data['up_to']) ? app(MessageService::class)->findInConversation($conversation, $data['up_to']) : null;
        $participant = $this->conversations->markRead($request->user(), $conversation, $upTo);

        return ApiResponse::success(['unread_count' => $participant->unread_count], __('api.updated'));
    }

    /**
     * "Everything so far reached this phone" — the grey double ticks.
     */
    public function delivered(Request $request)
    {
        return ApiResponse::success(['conversations' => $this->conversations->markAllDelivered($request->user())], __('api.updated'));
    }

    public function typing(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['state' => ['required', Rule::in(['typing', 'recording', 'stopped'])]]);
        $this->conversations->typing($request->user(), $conversation, $data['state']);

        return ApiResponse::success(null, 'OK');
    }

    public function disappearing(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['seconds' => ['present', 'nullable', Rule::in([86400, 604800, 7776000])]]);
        $me = $request->user();
        $this->conversations->setDisappearing($me, $conversation, $data['seconds']);

        return ApiResponse::success($this->conversations->resource($me, $this->conversations->participantOf($me, $conversation)), __('api.updated'));
    }
}
