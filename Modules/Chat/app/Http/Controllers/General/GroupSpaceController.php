<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupSpace;
use Modules\Chat\Services\GroupSpaceService;

/**
 * Terms, subgroups and projects inside an educational / work group (docs/groups_tasks.md GR-007).
 * `{conversation}` is the main group; members are given by their `participant_id` there.
 */
class GroupSpaceController extends Controller
{
    public function __construct(private readonly GroupSpaceService $spaces) {}

    /** GET groups/{c}/spaces — the ones I'm in (all of them for whoever manages spaces). */
    public function index(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->spaces->list($request->user(), $conversation), __('api.retrieved'));
    }

    /** POST groups/{c}/spaces — `{type, name, starts_on?, ends_on?, members[]}`. */
    public function store(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(ChatGroupSpace::TYPES)],
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'members' => ['sometimes', 'array', 'max:1024'],
            'members.*' => ['integer', 'distinct'],
        ]);

        return ApiResponse::created($this->spaces->create($request->user(), $conversation, $data), __('api.created'));
    }

    /** POST groups/{c}/spaces/{space}/members — `{members[]}` (main-group participant ids). */
    public function addMembers(Request $request, ChatConversation $conversation, int $space)
    {
        $data = $request->validate(['members' => ['required', 'array', 'min:1', 'max:1024'], 'members.*' => ['integer', 'distinct']]);

        return ApiResponse::success($this->spaces->addMembers($request->user(), $conversation, $space, $data['members']), __('api.updated'));
    }

    /** DELETE groups/{c}/spaces/{space}/members/{participant} — out of the space, still in the group. */
    public function removeMember(Request $request, ChatConversation $conversation, int $space, int $participant)
    {
        $this->spaces->removeMember($request->user(), $conversation, $space, $participant);

        return ApiResponse::success(null, __('api.updated'));
    }

    /** POST groups/{c}/spaces/{space}/next-term — `{name, starts_on?, ends_on?, members[]}`: this term closes, the chosen members move (GR-012). */
    public function nextTerm(Request $request, ChatConversation $conversation, int $space)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'members' => ['sometimes', 'array', 'max:1024'],
            'members.*' => ['integer', 'distinct'],
        ]);

        return ApiResponse::created($this->spaces->nextTerm($request->user(), $conversation, $space, $data), __('api.created'));
    }

    /** GET groups/{c}/spaces/{space}/members — its members as main-group participant ids (to pick who moves on). */
    public function members(Request $request, ChatConversation $conversation, int $space)
    {
        return ApiResponse::success(['participant_ids' => $this->spaces->memberIds($request->user(), $conversation, $space)], __('api.retrieved'));
    }

    /** POST groups/{c}/spaces/{space}/close — `{closed: bool}`: read only, or open again. */
    public function close(Request $request, ChatConversation $conversation, int $space)
    {
        $data = $request->validate(['closed' => ['required', 'boolean']]);

        return ApiResponse::success($this->spaces->close($request->user(), $conversation, $space, (bool) $data['closed']), __('api.updated'));
    }
}
