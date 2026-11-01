<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Offers\FreelancerOffers;
use Modules\Freelance\Services\Plans\FreelanceSubscriptions;

/**
 * The freelancer's plan (FL-L01): the plans with their prices in their country, their subscription and its invoices, taking or changing a
 * plan, turning renewal off and on, and paying a failed renewal again. The app reads once when the screen opens and after each action.
 */
class FreelancerSubscriptionController extends Controller
{
    public function __construct(private readonly FreelanceSubscriptions $subscriptions, private readonly FreelancerOffers $offers) {}

    /** GET plans — what each plan gives and costs here, and which one is mine. */
    public function plans(Request $request): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->catalog($this->me($request)), __('api.retrieved'));
    }

    /** GET subscription — my subscription (the live one, else the last), my invoices, and the free offers I have left. */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->body($this->me($request)), __('api.retrieved'));
    }

    /** POST subscription {plan, period} — take a plan; when one is live this changes it (better: now, lower: at the end of the period). */
    public function store(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate(['plan' => ['required', 'string', 'max:40'], 'period' => ['required', Rule::in(FreelancePlanPrice::PERIODS)]]);

        $this->subscriptions->take($me, $data['plan'], $data['period']);

        return ApiResponse::success($this->body($me), __('api.updated'));
    }

    /** POST subscription/cancel — renewal off; the plan runs to the end of the period. */
    public function cancel(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $this->subscriptions->cancel($me);

        return ApiResponse::success($this->body($me), __('api.updated'));
    }

    /** POST subscription/resume — renewal back on. */
    public function resume(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $this->subscriptions->resume($me);

        return ApiResponse::success($this->body($me), __('api.updated'));
    }

    /** POST subscription/renew — a failed renewal, tried again now. */
    public function renew(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $this->subscriptions->renewNow($me);

        return ApiResponse::success($this->body($me), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function body(Freelancer $me): array
    {
        return [
            'subscription' => $this->subscriptions->mine($me->refresh()),
            'invoices' => $this->subscriptions->invoices($me),
            'quota' => $this->offers->quota($me),
        ];
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
