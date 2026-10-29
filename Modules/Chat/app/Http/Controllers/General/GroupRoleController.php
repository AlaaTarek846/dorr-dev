<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Services\GroupRoleService;

/**
 * Roles in educational and work groups (docs/groups_tasks.md GR-004). Any member reads them;
 * changing them takes `roles.manage` (GroupRoleService).
 */
class GroupRoleController extends Controller
{
    public function __construct(private readonly GroupRoleService $roles) {}

    /** GET groups/{c}/roles — `{roles[], permissions[{key, name}], my_permissions[]}`. */
    public function index(Request $request, ChatConversation $conversation)
    {
        return ApiResponse::success($this->roles->list($request->user(), $conversation), __('api.retrieved'));
    }

    /** POST groups/{c}/roles — `{name, permissions[]}`. */
    public function store(Request $request, ChatConversation $conversation)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:60'],
            'permissions' => ['present', 'array', 'max:50'],
            'permissions.*' => ['string', 'max:40'],
        ]);
        $role = $this->roles->create($request->user(), $conversation, $data['name'], $data['permissions']);

        return ApiResponse::created($this->roles->present($role->loadCount('members')), __('api.created'));
    }

    /** PATCH groups/{c}/roles/{role} — `{name?, permissions?}` (a template's name back with null). */
    public function update(Request $request, ChatConversation $conversation, int $role)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'permissions' => ['sometimes', 'array', 'max:50'],
            'permissions.*' => ['string', 'max:40'],
        ]);

        return ApiResponse::success($this->roles->present($this->roles->update($request->user(), $conversation, $role, $data)), __('api.updated'));
    }

    public function destroy(Request $request, ChatConversation $conversation, int $role)
    {
        $this->roles->delete($request->user(), $conversation, $role);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** PUT groups/{c}/members/{participant}/roles — `{roles: [ids]}` replaces what they hold in the whole group. */
    public function member(Request $request, ChatConversation $conversation, int $participant)
    {
        $data = $request->validate([
            'roles' => ['present', 'array', 'max:20'],
            'roles.*' => ['integer'],
        ]);

        return ApiResponse::success(['roles' => $this->roles->setMemberRoles($request->user(), $conversation, $participant, array_map('intval', $data['roles']))], __('api.updated'));
    }
}
