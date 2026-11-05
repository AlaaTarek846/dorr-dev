<?php

namespace Modules\Provider\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Provider\Enums\MembershipStatus;
use Modules\Provider\Http\Requests\App\MemberRequest;
use Modules\Provider\Http\Resources\App\MembershipResource;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\MembershipEvent;
use Modules\Provider\Models\Provider;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\Provider\Services\Establishments\MembershipService;

/** The team (A-05): inviting by phone, permissions and branches, removing; and the invited person's answer. */
class MemberController extends Controller
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly EstablishmentAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $this->access->authorize($actor, 'members.manage');

        $members = $actor->establishment->memberships()
            ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Invited->value])
            ->with('provider')->orderByDesc('is_owner')->orderBy('id')->get();

        return ApiResponse::success(MembershipResource::collection($members), __('api.retrieved'));
    }

    public function store(MemberRequest $request): JsonResponse
    {
        $membership = $this->memberships->invite($this->access->current($request), [
            ...$request->safe()->only(['permissions', 'branch_ids', 'is_exclusive', 'offer_limit_minor']),
            'phone' => $request->fullPhone(),
        ]);

        return ApiResponse::created(new MembershipResource($membership->load('provider')), __('provider.member_invited'));
    }

    public function update(MemberRequest $request, Membership $membership): JsonResponse
    {
        $membership = $this->memberships->update($this->access->current($request), $membership, $request->validated());

        return ApiResponse::success(new MembershipResource($membership->load('provider')), __('api.updated'));
    }

    public function destroy(Request $request, Membership $membership): JsonResponse
    {
        $this->memberships->remove($this->access->current($request), $membership);

        return ApiResponse::noContent(__('provider.member_removed'));
    }

    /** Who changed what in the team (REQ-002). */
    public function events(Request $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $this->access->authorize($actor, 'members.manage');

        $events = MembershipEvent::query()->where('establishment_id', $actor->establishment_id)
            ->orderByDesc('id')->paginate(30);

        $people = Membership::query()->with('provider')->whereIn('id', collect($events->items())->pluck('membership_id')->filter())->get()->keyBy('id');
        $actors = Provider::query()->whereIn('id', collect($events->items())->pluck('actor_id')->filter())->get(['id', 'name', 'phone'])->keyBy('id');

        return ApiResponse::fromPaginator($events, collect($events->items())->map(fn (MembershipEvent $e) => [
            'action' => $e->action,
            'changes' => $e->changes,
            'member' => ($m = $people[$e->membership_id] ?? null) ? ['uuid' => $m->uuid, 'name' => $m->provider?->name, 'phone' => $m->provider?->phone ?? $m->phone] : null,
            'actor' => ($a = $actors[$e->actor_id] ?? null) ? ['name' => $a->name, 'phone' => $a->phone] : null,
            'created_at' => $e->created_at?->toIso8601String(),
        ])->values(), __('api.retrieved'));
    }

    public function accept(Request $request, Membership $membership): JsonResponse
    {
        $membership = $this->memberships->accept($request->user('provider_api'), $membership);

        return ApiResponse::success(new MembershipResource($membership->load('establishment')), __('provider.invitation_accepted'));
    }

    public function decline(Request $request, Membership $membership): JsonResponse
    {
        $this->memberships->decline($request->user('provider_api'), $membership);

        return ApiResponse::success([], __('provider.invitation_declined'));
    }

    public function leave(Request $request, Membership $membership): JsonResponse
    {
        $this->memberships->leave($request->user('provider_api'), $membership);

        return ApiResponse::success([], __('provider.left_establishment'));
    }
}
