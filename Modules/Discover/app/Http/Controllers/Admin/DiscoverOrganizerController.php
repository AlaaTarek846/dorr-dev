<?php

namespace Modules\Discover\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Chat\Support\ParticipantType;
use Modules\Discover\Models\DiscoverOrganizer;

/** Organizers waiting for review, verified, rejected or suspended (spec 180, AT-DISC-02). */
class DiscoverOrganizerController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('discover-organizers', [
            ['view', ['index', 'show']],
            ['update', ['review']],
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(DiscoverOrganizer::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = DiscoverOrganizer::query()->withCount('events')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))
            ->latest('id')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success($page->getCollection()->map(fn (DiscoverOrganizer $o) => $this->present($o))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page), [
            'pending_count' => DiscoverOrganizer::query()->where('status', 'pending')->count(),
        ]);
    }

    public function show(DiscoverOrganizer $discoverOrganizer)
    {
        return ApiResponse::success($this->present($discoverOrganizer->loadCount('events')), __('api.retrieved'));
    }

    /** PATCH {status: verified|rejected|suspended|pending, note?} */
    public function review(Request $request, DiscoverOrganizer $discoverOrganizer)
    {
        $data = $request->validate(['status' => ['required', Rule::in(DiscoverOrganizer::STATUSES)], 'note' => ['nullable', 'string', 'max:500']]);
        $discoverOrganizer->update([
            'status' => $data['status'],
            'review_note' => $data['note'] ?? null,
            'verified_at' => $data['status'] === 'verified' ? now() : null,
            'reviewed_by' => $request->user()?->getKey(),
        ]);

        return ApiResponse::success($this->present($discoverOrganizer->loadCount('events')), __('api.updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DiscoverOrganizer $o): array
    {
        $account = rescue(fn () => ParticipantType::modelClassFor($o->owner_type)::query()->find($o->owner_id), null, false);

        return [
            'id' => $o->id, 'name' => $o->name, 'about' => $o->about, 'website' => $o->website, 'phone' => $o->phone, 'email' => $o->email,
            'status' => $o->status, 'review_note' => $o->review_note, 'verified_at' => $o->verified_at?->toIso8601String(),
            'events_count' => (int) ($o->events_count ?? 0), 'created_at' => $o->created_at?->toIso8601String(),
            'account' => $account ? ['type' => $o->owner_type, 'id' => $account->getKey(), 'name' => $account->name ?? null, 'phone' => $account->phone ?? null] : null,
        ];
    }
}
