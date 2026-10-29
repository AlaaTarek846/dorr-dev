<?php

namespace Modules\Chat\Http\Controllers\Admin\Verification;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Admin\Models\Admin;
use Modules\Chat\Enums\VerificationBadgeStatus;
use Modules\Chat\Http\Middleware\EnsureVerificationPermission;
use Modules\Chat\Models\Verification\ChatVerificationBadge;
use Modules\Chat\Models\Verification\ChatVerificationBadgeEvent;
use Modules\Chat\Services\Verification\VerificationBadgeService;
use Modules\Chat\Services\Verification\VerificationRequestPresenter;
use Modules\User\Models\User;

/**
 * Admin → Chat → Verification → Badges (AV-023, AV-025): every account's badge, its trail, and the changes —
 * type and end, hold and back, withdraw — each with a reason the account reads, under `badges.update`.
 */
class VerificationBadgeController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly VerificationBadgeService $badges,
        private readonly VerificationRequestPresenter $presenter,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return EnsureVerificationPermission::map('chat-verification-badges', [
            ['view', ['index', 'show']],
            ['update', ['change', 'suspend', 'reactivate', 'withdraw']],
        ]);
    }

    /** GET chat-verification/badges[?status=&type=&search=&per_page=] — newest change first; `pagination.counts` per status. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(VerificationBadgeStatus::values())],
            'type' => ['nullable', Rule::in(['standard', 'celebrity'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = ChatVerificationBadge::query()->with('user')
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(isset($f['type']), fn ($q) => $q->where('type', $f['type']))
            ->when(isset($f['search']), function ($q) use ($f) {
                $term = '%'.addcslashes($f['search'], '%_\\').'%';
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->orderByDesc('updated_at')->paginate((int) ($f['per_page'] ?? 20));
        $counts = ChatVerificationBadge::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return ApiResponse::success(
            $page->getCollection()->map(fn (ChatVerificationBadge $b) => $this->row($b))->values(),
            __('api.retrieved'), 200,
            ApiPaginator::meta($page) + ['counts' => array_merge(array_fill_keys(VerificationBadgeStatus::values(), 0), $counts)],
        );
    }

    /** GET chat-verification/badges/{user} — the badge and its trail. */
    public function show(Request $request, User $user): JsonResponse
    {
        return $this->answer($request, $user, __('api.retrieved'));
    }

    /** POST …/{user}/change {type?, expires_at?, reason} */
    public function change(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'type' => ['sometimes', Rule::in(['standard', 'celebrity'])],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $changes = array_intersect_key($data, array_flip(['type', 'expires_at']));
        if (array_key_exists('expires_at', $changes)) {
            $changes['expires_at'] = $changes['expires_at'] === null ? null : Carbon::parse($changes['expires_at']);
        }
        $this->badges->change($this->admin($request), $user, $changes, $data['reason']);

        return $this->answer($request, $user);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->badges->suspend($this->admin($request), $user, $this->reason($request));

        return $this->answer($request, $user);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $this->badges->reactivate($this->admin($request), $user, $this->reason($request));

        return $this->answer($request, $user);
    }

    public function withdraw(Request $request, User $user): JsonResponse
    {
        $this->badges->withdraw($this->admin($request), $user, $this->reason($request));

        return $this->answer($request, $user);
    }

    private function answer(Request $request, User $user, ?string $message = null): JsonResponse
    {
        $badge = $this->badges->of($user);
        $admin = $this->admin($request);
        $can = EnsureVerificationPermission::allows($admin, 'chat-verification-badges.update');
        $status = $badge?->status;

        return ApiResponse::success([
            'user' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone],
            'badge' => $this->presenter->badge($badge),
            'history' => $badge === null ? [] : $badge->events()->get()->map(fn (ChatVerificationBadgeEvent $e) => [
                'from' => $e->from_status?->value,
                'to' => $e->to_status->value,
                'actor_type' => $e->actor_type,
                'actor_id' => $e->actor_id,
                'reason' => $e->reason,
                'changes' => $e->changes,
                'at' => $e->created_at?->toIso8601String(),
            ])->values(),
            'actions' => ! $can || $status === null ? [] : array_values(array_filter([
                in_array($status, [VerificationBadgeStatus::Temporary, VerificationBadgeStatus::Active], true) ? 'change' : null,
                $status->canMoveTo(VerificationBadgeStatus::Suspended) ? 'suspend' : null,
                $status === VerificationBadgeStatus::Suspended ? 'reactivate' : null,
                $status->canMoveTo(VerificationBadgeStatus::Withdrawn) ? 'withdraw' : null,
            ])),
        ], $message ?? __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function row(ChatVerificationBadge $b): array
    {
        return ['user' => $b->user === null ? null : ['id' => $b->user->id, 'name' => $b->user->name, 'phone' => $b->user->phone]]
            + $this->presenter->badge($b) + ['updated_at' => $b->updated_at?->toIso8601String()];
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];
    }

    private function admin(Request $request): Admin
    {
        return $request->user('admin_api');
    }
}
