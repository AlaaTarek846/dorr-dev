<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\TeamPlans;
use Modules\Freelance\Services\FreelancerTeam\TeamSubscriptions;

/**
 * FS-211: a freelancers' team's plan under `freelance/v1/teams/{team}/subscription`, behind the `teams` and `subscriptions` switches - the plans
 * with their prices and what each opens, the team's subscription with its invoices, and what the team holds against its room. Owners only
 * (the plan costs money out of an Owner's wallet, D7). Every read and every action answers with the same body, so the app reads once when the
 * screen opens and after each tap.
 */
class TeamSubscriptionController extends Controller
{
    public function __construct(private readonly TeamSubscriptions $subscriptions, private readonly TeamPlans $plans) {}

    /** GET teams/{team}/subscription - the plans on sale for the team, its subscription, its invoices and what it holds. */
    public function show(Request $request, string $team): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success($this->body($this->subscriptions->forOwner($team, $me), $me), __('api.retrieved'));
    }

    /** POST teams/{team}/subscription {plan, period} - take a plan for the team; when one is live this changes it (better: now, lower: at the end of the period). */
    public function store(Request $request, string $team): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->subscriptions->forOwner($team, $me);
        $data = $request->validate(['plan' => ['required', 'string', 'max:40'], 'period' => ['required', Rule::in(FreelancePlanPrice::PERIODS)]]);

        $this->subscriptions->take($t, $me, $data['plan'], $data['period']);

        return ApiResponse::success($this->body($t, $me), __('api.updated'));
    }

    /** POST teams/{team}/subscription/cancel - renewal off (any Owner); the plan runs to the end of the period. */
    public function cancel(Request $request, string $team): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->subscriptions->forOwner($team, $me);
        $this->subscriptions->cancel($t, $me);

        return ApiResponse::success($this->body($t, $me), __('api.updated'));
    }

    /** POST teams/{team}/subscription/resume - renewal back on (the Owner who pays). */
    public function resume(Request $request, string $team): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->subscriptions->forOwner($team, $me);
        $this->subscriptions->resume($t, $me);

        return ApiResponse::success($this->body($t, $me), __('api.updated'));
    }

    /** POST teams/{team}/subscription/renew - a failed renewal, tried again now (the Owner who pays). */
    public function renew(Request $request, string $team): JsonResponse
    {
        $me = $this->me($request);
        $t = $this->subscriptions->forOwner($team, $me);
        $this->subscriptions->renewNow($t, $me);

        return ApiResponse::success($this->body($t, $me), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function body(FreelanceTeam $team, Freelancer $me): array
    {
        return [
            'catalog' => $this->subscriptions->catalog($team),
            'subscription' => $this->subscriptions->mine($team, $me),
            'invoices' => $this->subscriptions->invoices($team),
            'usage' => $this->plans->usage($team),
        ];
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
