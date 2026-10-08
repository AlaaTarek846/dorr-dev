<?php

namespace Modules\AI\Services;

use Illuminate\Support\Facades\DB;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Models\AiSubscriptionPayment;

/**
 * Business-facing numbers for the admin subscriptions screen - deliberately
 * separate from AiSubscriptionPurchaseService (lifecycle/money logic) since
 * this is read-only reporting with its own, simpler correctness bar: every
 * figure here is a plain aggregate query, safe to run as often as the admin
 * dashboard wants without touching a single subscription row.
 */
class AiSubscriptionReportService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $activeBillable = AiSubscription::query()
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->whereNotNull('ends_at')
            ->where('current_plan_price', '>', 0);

        $mrr = (clone $activeBillable)
            ->where('auto_renew', true)
            ->get(['current_plan_price', 'current_plan_duration_days'])
            ->sum(fn (AiSubscription $s) => (float) $s->current_plan_price / max(1, (int) $s->current_plan_duration_days) * 30);

        $revenueThisMonth = (float) AiSubscriptionPayment::query()
            ->where('status', AiSubscriptionPayment::STATUS_SUCCEEDED)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');

        $cancelledOrExpired30d = AiSubscription::query()
            ->whereIn('status', [AiSubscription::STATUS_CANCELLED, AiSubscription::STATUS_EXPIRED])
            ->where('updated_at', '>=', now()->subDays(30))
            ->count();

        $activeNow = AiSubscription::query()->where('status', AiSubscription::STATUS_ACTIVE)->count();

        return [
            'trial_subscribers' => AiSubscription::query()
                ->where('status', AiSubscription::STATUS_ACTIVE)
                ->whereHas('plan', fn ($q) => $q->where('is_trial', true))
                ->count(),
            'paid_active' => (clone $activeBillable)->count(),
            'in_grace_period' => AiSubscription::query()
                ->where('status', AiSubscription::STATUS_ACTIVE)
                ->whereNotNull('grace_ends_at')
                ->where('grace_ends_at', '>=', now())
                ->count(),
            'suspended' => AiSubscription::query()->where('status', AiSubscription::STATUS_SUSPENDED)->count(),
            'cancelled_or_expired_last_30_days' => $cancelledOrExpired30d,
            // A simple, clearly-approximate churn rate: how many of the
            // subscriptions that were "live" over the last 30 days (still
            // active now, plus the ones that left) ended up leaving.
            'churn_rate_30d_percent' => $activeNow + $cancelledOrExpired30d > 0
                ? round($cancelledOrExpired30d / ($activeNow + $cancelledOrExpired30d) * 100, 1)
                : 0.0,
            'mrr' => round($mrr, 2),
            'revenue_this_month' => round($revenueThisMonth, 2),
            'plan_distribution' => $this->planDistribution(),
        ];
    }

    /**
     * @return list<array{plan_id: int, plan_name: string, active_subscribers: int}>
     */
    protected function planDistribution(): array
    {
        $rows = AiSubscription::query()
            ->select('plan_id', DB::raw('count(*) as active_subscribers'))
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->groupBy('plan_id')
            ->get()
            ->keyBy('plan_id');

        return AiPlan::query()
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->map(fn (AiPlan $plan) => [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'active_subscribers' => (int) ($rows->get($plan->id)->active_subscribers ?? 0),
            ])
            ->values()
            ->all();
    }
}
