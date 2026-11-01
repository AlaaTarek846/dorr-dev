<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceServiceSubscription as Sub;
use Modules\Freelance\Services\Market\ServiceSubscriptions;
use Modules\User\Models\User;

/**
 * FL-L05, the client's side: my monthly service subscriptions, confirming the next month (made, started and paid from the wallet in one
 * step), and ending one with notice — or taking that back.
 */
class ClientServiceSubscriptionController extends Controller
{
    public function __construct(private readonly ServiceSubscriptions $subscriptions) {}

    /** GET service-subscriptions[?status=pending|active|ended&per_page=] */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in([Sub::PENDING, Sub::ACTIVE, Sub::ENDED])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->subscriptions->page('client', $this->me($request)->id, $f['status'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn ($s) => $this->subscriptions->present($s, 'client'))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->answer($this->subscriptions->forClient($this->me($request), $uuid));
    }

    /** POST service-subscriptions/{uuid}/renew — the next month, paid from the wallet. */
    public function renew(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $this->subscriptions->renew($this->subscriptions->forClient($me, $uuid), $me);

        return $this->answer($this->subscriptions->forClient($me, $uuid), __('freelance.subscription_renewed'));
    }

    /** POST service-subscriptions/{uuid}/cancel | keep */
    public function action(Request $request, string $uuid, string $action): JsonResponse
    {
        $me = $this->me($request);
        $sub = $this->subscriptions->forClient($me, $uuid);
        $action === 'cancel' ? $this->subscriptions->cancel($sub, 'client') : $this->subscriptions->keep($sub, 'client');

        return $this->answer($this->subscriptions->forClient($me, $uuid), __('api.updated'));
    }

    private function answer(Sub $s, ?string $message = null): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->present($s, 'client'), $message ?? __('api.retrieved'));
    }

    private function me(Request $request): User
    {
        /** @var User */
        return $request->user('user_api');
    }
}
