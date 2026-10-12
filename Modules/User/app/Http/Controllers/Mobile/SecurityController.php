<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AccountSession;
use App\Models\SecurityEvent;
use App\Services\Security\AccountSessionService;
use App\Services\Security\SecurityPresenter;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\User\Models\User;

/**
 * Security in the app (spec items 28–29): the devices the account is signed in on — sign any of them out, or all but
 * this one — and the account's security log.
 */
class SecurityController extends Controller
{
    public function __construct(private readonly AccountSessionService $sessions) {}

    public function devices(Request $request): JsonResponse
    {
        return ApiResponse::success($this->deviceList($this->user($request)), __('api.retrieved'));
    }

    /** Signs one device out at once (this one too: that is the same as logging out). */
    public function signOut(Request $request, int $session): JsonResponse
    {
        $user = $this->user($request);
        $target = AccountSession::query()->of($user)->active()->findOrFail($session);
        $isCurrent = $target->id === $this->sessions->current($user)?->id;

        $this->sessions->signOut($user, $target);

        return ApiResponse::success([
            'signed_out_current' => $isCurrent,
            'devices' => $isCurrent ? [] : $this->deviceList($user),
        ], __('security.signed_out'));
    }

    public function signOutOthers(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $count = $this->sessions->signOutOthers($user);

        return ApiResponse::success([
            'count' => $count,
            'devices' => $this->deviceList($user),
        ], __('security.signed_out_others', ['count' => $count]));
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(SecurityEvent::ALL)],
            'warnings' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $paginator = SecurityEvent::query()->of($this->user($request))
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when((bool) ($data['warnings'] ?? false), fn ($q) => $q->whereIn('type', SecurityEvent::WARNINGS))
            ->latest('created_at')->latest('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return ApiResponse::success(
            $paginator->getCollection()->map(fn (SecurityEvent $event) => SecurityPresenter::event($event))->values(),
            __('api.retrieved'),
            200,
            ApiPaginator::meta($paginator),
        );
    }

    /** @return list<array<string, mixed>> */
    private function deviceList(User $user): array
    {
        $current = $this->sessions->current($user);

        return $this->sessions->active($user)
            // This device first, then the others by their last activity.
            ->sortBy(fn (AccountSession $s) => $s->id === $current?->id ? 0 : 1)
            ->map(fn (AccountSession $s) => SecurityPresenter::session($s, $current?->id))
            ->values()->all();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user('user_api');
    }
}
