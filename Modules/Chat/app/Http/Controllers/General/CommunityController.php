<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Models\ChatCommunity;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupJoinRequest;
use Modules\Chat\Services\CommunityService;

/**
 * Communities (WhatsApp list 93–98): mine, make one, its page (announcements + groups), add /
 * make / remove groups, hop into a group, members and admins, leave, delete.
 */
class CommunityController extends Controller
{
    public function __construct(private readonly CommunityService $communities) {}

    /** GET communities?page=&per_page=&q= — mine, newest first. Pages of 15. */
    public function index(Request $request)
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $q = isset($data['q']) ? trim((string) $data['q']) : null;
        $page = $this->communities->mine($request->user(), (int) ($data['per_page'] ?? 15), $q === '' ? null : $q);

        return ApiResponse::success($page->getCollection()->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** `{name, description?, groups?: [conversation ids I run], avatar?}` (multipart for the photo). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'groups' => ['nullable', 'array', 'max:'.CommunityService::MAX_GROUPS],
            'groups.*' => ['uuid'],
            'avatar' => ['nullable', 'image', 'max:5120'],
        ]);
        $me = $request->user();
        $community = $this->communities->create($me, $data['name'], $data['description'] ?? null, $data['groups'] ?? [], $request->file('avatar'));

        return ApiResponse::created($this->communities->show($me, $community), __('api.created'));
    }

    public function show(Request $request, ChatCommunity $community)
    {
        return ApiResponse::success($this->communities->show($request->user(), $community), __('api.retrieved'));
    }

    /** POST (multipart for the photo): `{name?, description?, avatar?}` — admins. */
    public function update(Request $request, ChatCommunity $community)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'avatar' => ['nullable', 'image', 'max:5120'],
        ]);
        $me = $request->user();
        $this->communities->update($me, $community, $data, $request->file('avatar'));

        return ApiResponse::success($this->communities->show($me, $community->refresh()), __('api.updated'));
    }

    /** `{groups: [conversation ids I run]}` — or `{name}` for a brand-new group inside it. */
    public function addGroups(Request $request, ChatCommunity $community)
    {
        $data = $request->validate([
            'groups' => ['required_without:name', 'array', 'max:'.CommunityService::MAX_GROUPS],
            'groups.*' => ['uuid'],
            'name' => ['required_without:groups', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $me = $request->user();
        if (isset($data['name'])) {
            $this->communities->newGroup($me, $community, $data['name'], $data['description'] ?? null);
        } else {
            $this->communities->addGroups($me, $community, $data['groups']);
        }

        return ApiResponse::success($this->communities->show($me, $community), __('api.updated'));
    }

    public function removeGroup(Request $request, ChatCommunity $community, ChatConversation $conversation)
    {
        $me = $request->user();
        $this->communities->removeGroup($me, $community, $conversation);

        return ApiResponse::success($this->communities->show($me, $community), __('api.updated'));
    }

    /** `status`: `joined`, or `pending` when that group's admins approve new members first. */
    public function joinGroup(Request $request, ChatCommunity $community, ChatConversation $conversation)
    {
        $result = $this->communities->joinGroup($request->user(), $community, $conversation);

        return ApiResponse::success([
            'status' => $result instanceof ChatGroupJoinRequest ? 'pending' : 'joined',
            'conversation_id' => $conversation->uuid,
        ], __('api.updated'));
    }

    public function members(Request $request, ChatCommunity $community)
    {
        return ApiResponse::success($this->communities->members($request->user(), $community), __('api.retrieved'));
    }

    public function removeMember(Request $request, ChatCommunity $community, int $participant)
    {
        $this->communities->removeMember($request->user(), $community, $participant);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** `{role: admin|member}` */
    public function role(Request $request, ChatCommunity $community, int $participant)
    {
        $data = $request->validate(['role' => ['required', Rule::in([ParticipantRole::Admin->value, ParticipantRole::Member->value])]]);
        $this->communities->setRole($request->user(), $community, $participant, ParticipantRole::from($data['role']));

        return ApiResponse::success(null, __('api.updated'));
    }

    public function leave(Request $request, ChatCommunity $community)
    {
        $this->communities->leave($request->user(), $community);

        return ApiResponse::success(null, __('api.updated'));
    }

    public function destroy(Request $request, ChatCommunity $community)
    {
        $this->communities->delete($request->user(), $community);

        return ApiResponse::success(null, __('api.deleted'));
    }
}
