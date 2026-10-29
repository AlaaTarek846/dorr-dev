<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Enums\GroupKind;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupMemberRole;
use Modules\Chat\Models\ChatGroupRole;
use Modules\Chat\Models\ChatGroupSpace;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\ChatBroadcaster;
use Modules\Chat\Support\GroupPermissions;
use Modules\Chat\Support\ParticipantType;

/**
 * Educational and work groups on the admin dashboard (docs/groups_tasks.md GR-050..053): the list
 * with its filters, one group's details, turning a group off (read only — its owner can't undo
 * it) or on, bringing a deleted one back, the role templates, the activity log and the numbers.
 *
 * Opening a group's members or log is looking at people's data: it takes `chat-groups.view` and is
 * itself recorded on that group's activity log.
 */
class ChatGroupAdminController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-groups', [
            ['view', ['index', 'stats', 'show', 'auditLog', 'templates']],
            ['update', ['disable', 'restore']],
        ]);
    }

    /** GET chat-groups?search=&kind=education|work&status=active|archived|disabled|deleted&per_page= */
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in(['education', 'work'])],
            'status' => ['nullable', Rule::in(['active', 'archived', 'disabled', 'deleted'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $status = $data['status'] ?? null;

        $page = $this->groups()
            ->when($status === 'deleted', fn ($q) => $q->onlyTrashed(), fn ($q) => $q->withoutTrashed())
            ->whereHas('group', fn ($g) => $g
                ->when($data['kind'] ?? null, fn ($g, $k) => $g->where('kind', $k))
                ->when($search !== '', fn ($g) => $g->where('name', 'like', "%{$search}%"))
                ->when($status === 'archived', fn ($g) => $g->whereNotNull('archived_at'))
                ->when($status === 'disabled', fn ($g) => $g->whereNotNull('disabled_at'))
                ->when($status === 'active', fn ($g) => $g->whereNull('archived_at')->whereNull('disabled_at')))
            ->with('group')->withCount(['participants as members_count' => fn ($q) => $q->whereNull('left_at')])
            ->latest('id')->paginate((int) ($data['per_page'] ?? 20));

        $owners = $this->owners($page->getCollection()->pluck('id')->all());

        return ApiResponse::success($page->getCollection()->map(fn (ChatConversation $c) => $this->row($c, $owners[$c->id] ?? null))->values(),
            __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** GET chat-groups/stats — the numbers for the top of the page. */
    public function stats()
    {
        $ids = $this->groups()->withTrashed()->pluck('id');
        $byKind = DB::table('chat_groups')->whereIn('conversation_id', $ids)->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');

        return ApiResponse::success([
            'education' => (int) ($byKind['education'] ?? 0),
            'work' => (int) ($byKind['work'] ?? 0),
            'archived' => DB::table('chat_groups')->whereIn('conversation_id', $ids)->whereNotNull('archived_at')->count(),
            'disabled' => DB::table('chat_groups')->whereIn('conversation_id', $ids)->whereNotNull('disabled_at')->count(),
            'deleted' => ChatConversation::onlyTrashed()->whereIn('id', $ids)->count(),
            'active_week' => DB::table('chat_messages')->whereIn('conversation_id', $ids)->where('created_at', '>=', now()->subDays(7))->distinct()->count('conversation_id'),
            'tasks_open' => DB::table('chat_group_tasks')->whereIn('conversation_id', $ids)->whereIn('status', ['new', 'in_progress', 'in_review'])->count(),
            'assignments' => DB::table('chat_group_assignments')->whereIn('conversation_id', $ids)->count(),
            'quizzes' => DB::table('chat_group_quizzes')->whereIn('conversation_id', $ids)->count(),
            // GR-053: reports on these groups still waiting.
            'reports_pending' => DB::table('chat_reports')->whereIn('conversation_id', $ids)->whereIn('status', ['pending', 'reviewing'])->count(),
        ], __('api.retrieved'));
    }

    /** GET chat-groups/{id} — details, members with their roles, roles, spaces. Recorded. */
    public function show(Request $request, string $group)
    {
        $conversation = $this->find($group);
        $this->record($request, $conversation, 'admin_viewed');

        $members = $conversation->participants()->whereNull('left_at')->get();
        $roleNames = ChatGroupRole::query()->where('conversation_id', $conversation->id)->get()->keyBy('id');
        $held = ChatGroupMemberRole::query()->whereIn('participant_id', $members->pluck('id'))->whereNull('space_id')->get()->groupBy('participant_id');
        $accounts = $members->groupBy('participant_type')->flatMap(fn ($rows, $type) => ParticipantType::modelClassFor($type)::query()->whereIn('id', $rows->pluck('participant_id'))->get()
            ->mapWithKeys(fn ($m) => [$type.':'.$m->getKey() => $m]));

        return ApiResponse::success($this->row($conversation, $this->owners([$conversation->id])[$conversation->id] ?? null) + [
            'description' => $conversation->group->description,
            'join_mode' => $conversation->group->join_mode,
            'members' => $members->map(fn (ChatParticipant $p) => [
                'participant_id' => $p->id,
                'name' => $accounts->get($p->participant_type.':'.$p->participant_id)?->name,
                'phone' => $accounts->get($p->participant_type.':'.$p->participant_id)?->phone,
                'role' => $p->role->value,
                'roles' => ($held[$p->id] ?? collect())->map(fn ($r) => $roleNames->get($r->role_id)?->displayName())->filter()->values()->all(),
                'joined_at' => $p->joined_at?->toIso8601String(),
            ])->values()->all(),
            'roles' => $roleNames->map(fn (ChatGroupRole $r) => ['id' => $r->id, 'name' => $r->displayName(), 'template' => $r->template, 'permissions' => $r->permissions, 'is_default' => $r->is_default])->values()->all(),
            'spaces' => ChatGroupSpace::query()->where('parent_conversation_id', $conversation->id)->with('conversation.group')->get()
                ->map(fn ($s) => ['id' => $s->id, 'type' => $s->type, 'name' => $s->conversation?->group?->name, 'is_closed' => $s->closed_at !== null])->values()->all(),
        ], __('api.retrieved'));
    }

    /** GET chat-groups/{id}/audit-log?page= — read only. Recorded. */
    public function auditLog(Request $request, string $group)
    {
        $conversation = $this->find($group);
        $this->record($request, $conversation, 'admin_viewed', ['what' => 'audit_log']);
        $page = ChatGroupAuditLog::query()->where('conversation_id', $conversation->id)->latest('id')->paginate(30);

        $names = $page->getCollection()->filter(fn ($r) => $r->actor_type !== null && $r->actor_type !== 'admin')->groupBy('actor_type')
            ->flatMap(fn ($rows, $type) => ParticipantType::isEnabled($type)
                ? ParticipantType::modelClassFor($type)::query()->whereIn('id', $rows->pluck('actor_id'))->get()->mapWithKeys(fn ($m) => [$type.':'.$m->getKey() => $m->name])
                : []);

        return ApiResponse::success($page->getCollection()->map(fn (ChatGroupAuditLog $r) => [
            'id' => $r->id,
            'action' => $r->action,
            'text' => __('chat.audit.'.$r->action),
            'actor' => $r->actor_type === 'admin' ? __('chat.audit_admin') : $names->get($r->actor_type.':'.$r->actor_id),
            'subject' => $r->subject,
            'before' => $r->before,
            'after' => $r->after,
            'created_at' => $r->created_at?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** GET chat-groups/templates — the roles a new group starts with, and every permission (GR-052). */
    public function templates()
    {
        $out = [];
        foreach ([GroupKind::Education, GroupKind::Work] as $kind) {
            $t = GroupPermissions::templates($kind);
            $out[$kind->value] = [
                'leader' => $t['leader'],
                'default' => $t['default'],
                'roles' => collect($t['roles'])->map(fn ($perms, $template) => ['template' => $template, 'name' => __('chat.group_roles.'.$template), 'permissions' => $perms])->values()->all(),
                'permissions' => collect(GroupPermissions::catalogue($kind))->map(fn ($k) => ['key' => $k, 'name' => __('chat.group_permissions.'.$k)])->all(),
            ];
        }

        return ApiResponse::success($out, __('api.retrieved'));
    }

    /** PATCH chat-groups/{id}/disable `{disabled}` — read only for everyone; the owner can't undo it. */
    public function disable(Request $request, string $group)
    {
        $data = $request->validate(['disabled' => ['required', 'boolean']]);
        $conversation = $this->find($group);
        $conversation->group->update(['disabled_at' => $data['disabled'] ? now() : null]);
        $this->record($request, $conversation, $data['disabled'] ? 'admin_disabled' : 'admin_enabled');
        $this->changed($conversation);

        return ApiResponse::success($this->row($conversation->refresh(), $this->owners([$conversation->id])[$conversation->id] ?? null), __('api.updated'));
    }

    /** POST chat-groups/{id}/restore — a deleted group back (before it's purged), with its spaces. */
    public function restore(Request $request, string $group)
    {
        $conversation = $this->find($group);
        abort_unless($conversation->trashed(), 422, __('chat.errors.group_not_restorable'));

        DB::transaction(function () use ($conversation) {
            $conversation->restore();
            ChatConversation::onlyTrashed()->whereIn('id', ChatGroupSpace::query()->where('parent_conversation_id', $conversation->id)->pluck('conversation_id'))->get()->each->restore();
            $conversation->group->update(['purge_after' => null]);
        });
        $this->record($request, $conversation, 'admin_restored');
        $this->changed($conversation);

        return ApiResponse::success($this->row($conversation->refresh(), $this->owners([$conversation->id])[$conversation->id] ?? null), __('api.updated'));
    }

    // ------------------------------------------------------------------ helpers

    private function groups()
    {
        return ChatConversation::query()->where('type', ConversationType::Group->value)
            ->whereHas('group', fn ($g) => $g->whereIn('kind', ['education', 'work']));
    }

    private function find(string $uuid): ChatConversation
    {
        return $this->groups()->withTrashed()->where('uuid', $uuid)->with('group')->firstOrFail();
    }

    /** @return array<int, array{name: ?string, phone: ?string}> conversation id => its owner */
    private function owners(array $ids): array
    {
        $rows = ChatParticipant::query()->whereIn('conversation_id', $ids)->whereNull('left_at')->where('role', ParticipantRole::Owner->value)->get();
        $out = [];
        foreach ($rows as $row) {
            $account = $row->participant();
            $out[$row->conversation_id] = ['name' => $account?->name, 'phone' => $account?->phone];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function row(ChatConversation $c, ?array $owner): array
    {
        $g = $c->group;

        return [
            'id' => $c->uuid,
            'name' => $g->name,
            'kind' => $g->kind?->value,
            'owner' => $owner,
            'members_count' => (int) ($c->members_count ?? $c->participants()->whereNull('left_at')->count()),
            'status' => $c->trashed() ? 'deleted' : ($g->disabled_at ? 'disabled' : ($g->archived_at ? 'archived' : 'active')),
            'members_hidden' => $g->membersHidden(),
            'purge_after' => $g->purge_after?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    /** What the admin did or looked at goes on the group's own activity log. */
    private function record(Request $request, ChatConversation $conversation, string $action, array $extra = []): void
    {
        $admin = $request->user();
        ChatGroupAuditLog::query()->create([
            'conversation_id' => $conversation->id,
            'actor_type' => 'admin',
            'actor_id' => $admin?->getKey(),
            'action' => $action,
            'after' => ['admin' => $admin?->name] + $extra,
        ]);
    }

    private function changed(ChatConversation $conversation): void
    {
        app(ChatBroadcaster::class)->toParticipants($conversation->participants()->whereNull('left_at')->get(), 'chat.conversation.updated', ['conversation_id' => $conversation->uuid]);
    }
}
