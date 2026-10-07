<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\AI\Exceptions\AiSubscriptionException;
use Modules\AI\Http\Requests\AiAutoRenewRequest;
use Modules\AI\Http\Requests\AiChangePlanRequest;
use Modules\AI\Http\Requests\AiSubscribeRequest;
use Modules\AI\Http\Resources\AiPlanPricingResource;
use Modules\AI\Http\Resources\AiSubscriptionPaymentResource;
use Modules\AI\Http\Resources\AiSubscriptionResource;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Models\AiSubscriptionPayment;
use Modules\AI\Services\AiSubscriptionPurchaseService;

/**
 * The self-service half of the subscription system - shared between the
 * User and Provider guards exactly like AiChatController (see its own
 * owner() docblock). Money-moving actions (subscribe, change-plan) sit
 * behind RequiresWalletPin at the route level (Modules/AI/routes/user.php
 * and provider.php) - this controller only orchestrates, it never touches
 * a wallet balance directly (that's AiSubscriptionBillingService, reached
 * through AiSubscriptionPurchaseService).
 */
class AiUserSubscriptionController extends Controller
{
    public function __construct(protected AiSubscriptionPurchaseService $subscriptions) {}

    public function plans()
    {
        $country = currentCountry();

        $plans = AiPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            // currencyRef: avoids an N+1 currency lookup per plan now that
            // the base currency is a real relation (see AiPlan::getCurrencyAttribute()).
            ->with('currencyRef')
            ->with(['prices' => function ($query) use ($country) {
                $query->when($country !== null, fn ($q) => $q->where('country_id', $country->id))
                    ->when($country === null, fn ($q) => $q->whereRaw('1 = 0'))
                    ->with('currency');
            }])
            ->get();

        return ApiResponse::success(AiPlanPricingResource::collectionFor($plans, $country));
    }

    public function current(Request $request)
    {
        $owner = $this->owner($request);

        $subscription = AiSubscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->with('plan')
            ->latest('id')
            ->first();

        if ($subscription === null) {
            return ApiResponse::success(null);
        }

        return ApiResponse::success(new AiSubscriptionResource($subscription));
    }

    public function payments(Request $request)
    {
        $owner = $this->owner($request);

        $query = AiSubscriptionPayment::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->with('plan')
            ->latest('id');

        $result = allOrPaginate($query, AiSubscriptionPaymentResource::class);

        return ApiResponse::success($result['data'], __('api.retrieved'), 200, $result['pagination']);
    }

    public function subscribe(AiSubscribeRequest $request)
    {
        $plan = AiPlan::query()->find($request->integer('plan_id'));

        if ($plan === null) {
            return ApiResponse::error(__('ai.subscription_plan_not_found'), 404);
        }

        try {
            $subscription = $this->subscriptions->subscribe(
                $this->owner($request),
                $plan,
                $request->boolean('auto_renew', true),
            );
        } catch (AiSubscriptionException $e) {
            return ApiResponse::error($e->apiMessage(), $e->apiStatus());
        }

        return ApiResponse::success(new AiSubscriptionResource($subscription->load('plan')), __('ai.subscription_created'));
    }

    public function changePlan(AiChangePlanRequest $request)
    {
        $owner = $this->owner($request);
        $plan = AiPlan::query()->find($request->integer('plan_id'));

        if ($plan === null) {
            return ApiResponse::error(__('ai.subscription_plan_not_found'), 404);
        }

        $current = $this->activeSubscriptionOrFail($owner);

        if ($current instanceof \Illuminate\Http\JsonResponse) {
            return $current;
        }

        try {
            $subscription = $this->subscriptions->changePlan($owner, $current, $plan);
        } catch (AiSubscriptionException $e) {
            return ApiResponse::error($e->apiMessage(), $e->apiStatus());
        }

        return ApiResponse::success(new AiSubscriptionResource($subscription), __('ai.subscription_plan_changed'));
    }

    public function updateAutoRenew(AiAutoRenewRequest $request)
    {
        $owner = $this->owner($request);
        $current = $this->activeSubscriptionOrFail($owner);

        if ($current instanceof \Illuminate\Http\JsonResponse) {
            return $current;
        }

        $autoRenew = $request->boolean('auto_renew');

        try {
            $subscription = $this->subscriptions->setAutoRenew($owner, $current, $autoRenew);
        } catch (AiSubscriptionException $e) {
            return ApiResponse::error($e->apiMessage(), $e->apiStatus());
        }

        return ApiResponse::success(
            new AiSubscriptionResource($subscription),
            __($autoRenew ? 'ai.subscription_auto_renew_enabled' : 'ai.subscription_auto_renew_disabled'),
        );
    }

    /**
     * @return AiSubscription|\Illuminate\Http\JsonResponse
     */
    protected function activeSubscriptionOrFail(Authenticatable $owner)
    {
        $subscription = AiSubscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->with('plan')
            ->latest('id')
            ->first();

        if ($subscription === null) {
            return ApiResponse::error(__('ai.subscription_not_found'), 404);
        }

        return $subscription;
    }

    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api') ?? $request->user('provider_api');
    }
}
