<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatPortal;
use Modules\Chat\Support\ParticipantType;

/**
 * The admin's view of merchant portals (switch one off) and of channels (verify one by hand —
 * besides the paid verification).
 */
class ChatDirectoryController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return array_merge(
            AdminPermissionMiddleware::fromActionMethodMap('chat-portals', [
                ['view', ['portals']],
                ['update', ['portalStatus']],
            ]),
            AdminPermissionMiddleware::fromActionMethodMap('chat-channels', [
                ['view', ['channels']],
                ['update', ['verify']],
            ]),
        );
    }

    public function portals(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'listed' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $page = ChatPortal::query()->with(['translations', 'media', 'category.translations'])
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('website_url', 'like', "%{$search}%")
                ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$search}%"))))
            ->when(isset($data['listed']), fn ($q) => $data['listed'] ? $q->listed() : $q->where(fn ($q) => $q->whereNull('listed_until')->orWhere('listed_until', '<=', now())))
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        // The merchants behind this page of portals, one query per account type.
        $owners = $page->getCollection()->groupBy('owner_type')
            ->flatMap(fn ($rows, $type) => ParticipantType::modelClassFor($type)::query()->whereIn('id', $rows->pluck('owner_id'))->get()
                ->mapWithKeys(fn ($m) => [$type.':'.$m->getKey() => $m]));

        return ApiResponse::success($page->getCollection()->map(fn (ChatPortal $p) => [
            'id' => $p->uuid,
            'name' => $p->translatedName(),
            'logo' => $p->logoUrl(),
            'website_url' => $p->website_url,
            'category' => $p->category?->translatedName(),
            'owner' => $owners->get($p->owner_type.':'.$p->owner_id)?->name,
            'owner_phone' => $owners->get($p->owner_type.':'.$p->owner_id)?->phone,
            'views_count' => $p->views_count,
            'status' => $p->status,
            'is_listed' => $p->isListed(),
            'listed_until' => $p->listed_until?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    public function portalStatus(Request $request, ChatPortal $portal)
    {
        $portal->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success(['id' => $portal->uuid, 'status' => $portal->status], __('api.updated'));
    }

    public function channels(Request $request)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'verified' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $page = ChatConversation::query()->where('type', ConversationType::Channel->value)
            ->whereHas('group', fn ($q) => $q
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('handle', 'like', "%{$search}%")))
                ->when(isset($data['verified']), fn ($q) => $data['verified']
                    ? $q->where(fn ($q) => $q->where('verified_by_admin', true)->orWhere('verified_until', '>', now()))
                    : $q->where('verified_by_admin', false)->where(fn ($q) => $q->whereNull('verified_until')->orWhere('verified_until', '<=', now()))))
            ->with(['group.media', 'group.category.translations'])
            ->withCount(['participants as followers_count' => fn ($q) => $q->whereNull('left_at')])
            ->orderByDesc('followers_count')
            ->paginate((int) ($data['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn (ChatConversation $c) => [
            'id' => $c->uuid,
            'name' => $c->group?->name,
            'handle' => $c->group?->handle,
            'avatar' => $c->group?->avatarUrl(),
            'is_public' => (bool) $c->group?->is_public,
            'category' => $c->group?->category?->translatedName(),
            'followers_count' => (int) $c->followers_count,
            'is_verified' => (bool) $c->group?->isVerified(),
            'verified_by_admin' => (bool) $c->group?->verified_by_admin,
            'verified_until' => $c->group?->verified_until?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** PATCH chat-channels/{uuid}/verify — `{verified: bool}`: the admin's own ✔ (a paid one runs on regardless). */
    public function verify(Request $request, string $channel)
    {
        $data = $request->validate(['verified' => ['required', 'boolean']]);
        $conversation = ChatConversation::query()->where('type', ConversationType::Channel->value)->where('uuid', $channel)->with('group')->firstOrFail();
        $conversation->group->update(['verified_by_admin' => $data['verified']]);

        return ApiResponse::success(['id' => $conversation->uuid, 'is_verified' => $conversation->group->isVerified(), 'verified_by_admin' => (bool) $conversation->group->verified_by_admin], __('api.updated'));
    }
}
