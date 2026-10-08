<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Modules\AI\Http\Requests\AiSubscriptionExtendRequest;
use Modules\AI\Http\Resources\AiSubscriptionResource;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Services\AiSubscriptionPurchaseService;
use Modules\AI\Services\AiSubscriptionReportService;

/**
 * The admin-facing half of the subscription system that isn't plain CRUD:
 * business numbers for the dashboard, and free (non-wallet) adjustments an
 * admin makes as a support/business decision - extend, suspend, reactivate,
 * cancel. Deliberately a separate controller from AiSubscriptionController
 * (the generic CRUD one) so that one can stay a thin BaseService wrapper.
 */
class AiSubscriptionAdminController extends Controller
{
    public function __construct(
        protected AiSubscriptionReportService $reports,
        protected AiSubscriptionPurchaseService $purchases,
    ) {}

    public function overview()
    {
        return ApiResponse::success($this->reports->overview(), __('api.retrieved'));
    }

    public function extend(AiSubscriptionExtendRequest $request, AiSubscription $subscription)
    {
        $subscription = $this->purchases->adminExtend($subscription, (int) $request->validated('days'));

        return ApiResponse::success(new AiSubscriptionResource($subscription), __('ai.subscription_extended'));
    }

    public function suspend(AiSubscription $subscription)
    {
        $subscription = $this->purchases->adminSuspend($subscription);

        return ApiResponse::success(new AiSubscriptionResource($subscription), __('ai.subscription_suspended_by_admin'));
    }

    public function reactivate(AiSubscription $subscription)
    {
        $subscription = $this->purchases->adminReactivate($subscription);

        return ApiResponse::success(new AiSubscriptionResource($subscription), __('ai.subscription_reactivated'));
    }

    public function cancel(AiSubscription $subscription)
    {
        $subscription = $this->purchases->adminCancel($subscription);

        return ApiResponse::success(new AiSubscriptionResource($subscription), __('ai.subscription_cancelled_by_admin'));
    }
}
