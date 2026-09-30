<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Http\Requests\CreateGroupRequest;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupJoinRequest;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\GroupService;
use Modules\Chat\Support\ParticipantType;

/**
 * Groups (docs/chat-plan.md §3.6). Members are referenced by account id when adding, and by
 * `participant_id` (their row in the group, from `members`) when managing them.
 */
class GroupController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
        private readonly ConversationService $conversations,
    ) {}

    public function store(CreateGroupRequest $request)
    {
        $me = $request->user();
        $result = $this->groups->create(
            $me,
            $request->validated('name'),
            $request->validated('description'),
            $this->accounts($request->validated('members')),
            $request->file('avatar'),
            $request->validated('disappearing_seconds'),
        );

        return ApiResponse::created([
            'conversation' => $this->conversations->resource($me, $result['participant']),
            'not_added' => $result['not_added'],
        ], __('api.created'));
    }

    public function update(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'avatar' => ['nullable', 'image', 'max:10240'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ]);

        $me = $request->user();
        $this->groups->updateInfo($me, $conversation, $data, $request->file('avatar'), $request->boolean('remove_avatar'));

        return ApiResponse::success($this->conversations->show($me, $conversation->refresh()), __('api.updated'));
    }

    public function settings(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'only_admins_send' => ['sometimes', 'boolean'],
            'only_admins_edit_info' => ['sometimes', 'boolean'],
            'only_admins_add_members' => ['sometimes', 'boolean'],
            'approve_joins' => ['sometimes', 'boolean'],
            // Channels only: listed in Discover or not.
            'is_public' => ['sometimes', 'boolean'],
        ]);

        $me = $request->user();
        $this->groups->updateSettings($me, $conversation, $data);

        return ApiResponse::success($this->conversations->show($me, $conversation->refresh()), __('api.updated'));
    }

    public function members(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->groups->members($request->user(), $conversation), __('api.retrieved'));
    }

    public function addMembers(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate(['members' => ['required', 'array', 'min:1', 'max:1024'], 'members.*' => ['integer', 'distinct']]);

        return ApiResponse::success($this->groups->addMembers($request->user(), $conversation, $this->accounts($data['members'])), __('api.updated'));
    }

    public function removeMember(Request $request, ChatConversation $conversation, int $participant)
    {
        $this->groups->removeMember($request->user(), $conversation, $participant);

        return ApiResponse::success($this->groups->members($request->user(), $conversation), __('api.updated'));
    }

    public function role(Request $request, ChatConversation $conversation, int $participant)
    {
        $data = $request->validate(['role' => ['required', 'in:admin,member']]);
        $this->groups->setRole($request->user(), $conversation, $participant, ParticipantRole::from($data['role']));

        return ApiResponse::success($this->groups->members($request->user(), $conversation), __('api.updated'));
    }

    public function leave(Request $request, ChatConversation $conversation)
    {
        $this->groups->leave($request->user(), $conversation);

        return ApiResponse::success(null, __('api.updated'));
    }

    public function invite(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->groups->inviteLink($request->user(), $conversation), __('api.retrieved'));
    }

    public function resetInvite(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->groups->inviteLink($request->user(), $conversation, reset: true), __('api.updated'));
    }

    public function previewInvite(Request $request, string $token)
    {
        return ApiResponse::success($this->groups->previewInvite($request->user(), $token), __('api.retrieved'));
    }

    /**
     * 200 + the conversation when I'm in; 202 + `{status: pending}` when the admins approve joins.
     */
    public function join(Request $request, string $token)
    {
        $me = $request->user();
        $result = $this->groups->join($me, $token);

        if ($result instanceof ChatGroupJoinRequest) {
            return ApiResponse::success(['status' => 'pending', 'request_id' => $result->id], __('chat.join_requested'), 202);
        }

        return ApiResponse::success($this->conversations->resource($me, $result), __('api.updated'));
    }

    public function cancelJoin(Request $request, string $token)
    {
        $this->groups->cancelJoinRequest($request->user(), $token);

        return ApiResponse::success(null, __('api.updated'));
    }

    public function joinRequests(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->groups->joinRequests($request->user(), $conversation), __('api.retrieved'));
    }

    public function approveJoin(Request $request, ChatConversation $conversation, int $joinRequest)
    {
        $this->groups->decideJoinRequest($request->user(), $conversation, $joinRequest, true);

        return ApiResponse::success($this->groups->joinRequests($request->user(), $conversation), __('api.updated'));
    }

    public function rejectJoin(Request $request, ChatConversation $conversation, int $joinRequest)
    {
        $this->groups->decideJoinRequest($request->user(), $conversation, $joinRequest, false);

        return ApiResponse::success($this->groups->joinRequests($request->user(), $conversation), __('api.updated'));
    }

    /**
     * @param  list<int>  $ids
     * @return list<Model>
     */
    private function accounts(array $ids): array
    {
        return ParticipantType::modelClassFor('user')::query()->whereIn('id', $ids)->get()->all();
    }
}
