<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AccountSession;
use App\Models\SecurityEvent;
use App\Services\Security\AccountSessionService;
use App\Services\Security\SecurityPresenter;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\User\Models\User;

/**
 * Dashboard side of account security: the security log of every customer (sign-ins, failed codes, devices signed out,
 * PIN locks…) and, per customer, the devices they are signed in on — support can sign any of them out.
 */
class SecurityAdminController extends Controller implements HasMiddleware
{
    public function __construct(private readonly AccountSessionService $sessions) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [
            ...AdminPermissionMiddleware::fromActionMethodMap('security-events', [
                ['view', ['events', 'event']],
            ]),
            ...AdminPermissionMiddleware::fromActionMethodMap('users', [
                ['manage-sessions', ['sessions', 'signOut', 'signOutAll']],
            ]),
        ];
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(SecurityEvent::ALL)],
            'warnings' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $userMorph = (new User)->getMorphClass();
        $search = trim((string) ($data['search'] ?? ''));

        $paginator = SecurityEvent::query()
            ->where('owner_type', $userMorph)
            ->when($data['user_id'] ?? null, fn ($q, $id) => $q->where('owner_id', $id))
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when((bool) ($data['warnings'] ?? false), fn ($q) => $q->whereIn('type', SecurityEvent::WARNINGS))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('ip', 'like', "%{$search}%")
                ->orWhere('device_name', 'like', "%{$search}%")
                ->orWhereIn('owner_id', User::query()->withTrashed()->where('phone', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")->select('id'))))
            ->latest('created_at')->latest('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        $users = User::query()->withTrashed()->whereIn('id', $paginator->getCollection()->pluck('owner_id')->unique())->get(['id', 'name', 'phone'])->keyBy('id');

        return ApiResponse::success(
            $paginator->getCollection()->map(fn (SecurityEvent $event) => SecurityPresenter::event($event, true) + [
                'user' => ($u = $users->get($event->owner_id)) ? ['id' => $u->id, 'name' => $u->name, 'is_verified' => \App\Support\AccountBadge::verified($u), 'phone' => $u->phone] : null,
            ])->values(),
            __('api.retrieved'),
            200,
            ApiPaginator::meta($paginator),
        );
    }

    public function event(int $event): JsonResponse
    {
        $row = SecurityEvent::query()->where('owner_type', (new User)->getMorphClass())->with('session.token')->findOrFail($event);
        $user = User::query()->withTrashed()->find($row->owner_id, ['id', 'name', 'phone']);

        return ApiResponse::success(SecurityPresenter::event($row, true) + [
            'user' => $user ? ['id' => $user->id, 'name' => $user->name, 'is_verified' => \App\Support\AccountBadge::verified($user), 'phone' => $user->phone] : null,
            'session' => $row->session ? SecurityPresenter::session($row->session, null, true) + ['user_agent' => $row->session->user_agent] : null,
        ], __('api.retrieved'));
    }

    /** A customer's devices: the ones signed in now, then the last ones signed out. */
    public function sessions(User $user): JsonResponse
    {
        $active = $this->sessions->active($user);
        $recent = AccountSession::query()->of($user)->whereNotNull('revoked_at')->latest('revoked_at')->limit(10)->get();

        return ApiResponse::success([
            'active' => $active->map(fn (AccountSession $s) => SecurityPresenter::session($s, null, true) + ['user_agent' => $s->user_agent])->values(),
            'recent' => $recent->map(fn (AccountSession $s) => SecurityPresenter::session($s, null, true))->values(),
        ], __('api.retrieved'));
    }

    public function signOut(User $user, int $session): JsonResponse
    {
        $target = AccountSession::query()->of($user)->active()->findOrFail($session);
        $this->sessions->signOut($user, $target, AccountSession::REASON_ADMIN);

        return $this->sessions($user);
    }

    public function signOutAll(User $user): JsonResponse
    {
        $this->sessions->signOutOthers($user, AccountSession::REASON_ADMIN);

        return $this->sessions($user);
    }
}
