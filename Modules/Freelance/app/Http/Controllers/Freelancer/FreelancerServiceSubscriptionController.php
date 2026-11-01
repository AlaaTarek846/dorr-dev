<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceServiceSubscription as Sub;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Market\ServiceSubscriptions;

/** FL-L05, the freelancer's side: the monthly subscriptions to my services, and ending one with notice — or taking that back. */
class FreelancerServiceSubscriptionController extends Controller
{
    public function __construct(private readonly ServiceSubscriptions $subscriptions) {}

    /** GET service-subscriptions[?status=pending|active|ended&per_page=] */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in([Sub::PENDING, Sub::ACTIVE, Sub::ENDED])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->subscriptions->page('freelancer', $this->me($request)->id, $f['status'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn ($s) => $this->subscriptions->present($s, 'freelancer'))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->present($this->subscriptions->forFreelancer($this->me($request), $uuid), 'freelancer'), __('api.retrieved'));
    }

    /** POST service-subscriptions/{uuid}/cancel | keep */
    public function action(Request $request, string $uuid, string $action): JsonResponse
    {
        $me = $this->me($request);
        $sub = $this->subscriptions->forFreelancer($me, $uuid);
        $action === 'cancel' ? $this->subscriptions->cancel($sub, 'freelancer') : $this->subscriptions->keep($sub, 'freelancer');

        return ApiResponse::success($this->subscriptions->present($this->subscriptions->forFreelancer($me, $uuid), 'freelancer'), __('api.updated'));
    }

    private function me(Request $request): Freelancer
    {
        /** @var Freelancer */
        return $request->user('freelance_api');
    }
}
