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

        // Prices are per country, so subscriptions are paid in different currencies: money is summed per currency,
        // never across currencies. A subscription's currency is the one its last successful payment was made in
        // (the plan's own currency when it has none).
        $renewing = (clone $activeBillable)
            ->where('auto_renew', true)
            ->with('plan.currencyRef')
            ->get(['id', 'plan_id', 'current_plan_price', 'current_plan_duration_days']);

        $paidCurrencies = AiSubscriptionPayment::query()
            ->whereIn('subscription_id', $renewing->pluck('id'))
            ->where('status', AiSubscriptionPayment::STATUS_SUCCEEDED)
            ->whereNotNull('currency')
            ->orderBy('id')
            ->pluck('currency', 'subscription_id'); // the last payment wins (ordered by id)

        $mrrByCurrency = $renewing
            ->groupBy(fn (AiSubscription $s) => $this->currencyCode($paidCurrencies->get($s->id) ?? $s->plan?->currency))
            ->map(fn ($group) => $group->sum(fn (AiSubscription $s) => (float) $s->current_plan_price / max(1, (int) $s->current_plan_duration_days) * 30));

        $revenueByCurrency = AiSubscriptionPayment::query()
            ->where('status', AiSubscriptionPayment::STATUS_SUCCEEDED)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->get(['currency', 'amount'])
            ->groupBy(fn (AiSubscriptionPayment $p) => $this->currencyCode($p->currency))
            ->map(fn ($group) => $group->sum(fn (AiSubscriptionPayment $p) => (float) $p->amount));

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
            // One figure per currency ([{currency, amount}], largest first). The single numbers stay for old
            // callers but only when everything is in one currency - null when currencies are mixed.
            'mrr_by_currency' => $this->perCurrency($mrrByCurrency),
            'revenue_this_month_by_currency' => $this->perCurrency($revenueByCurrency),
            'mrr' => $this->singleCurrencyTotal($mrrByCurrency),
            'revenue_this_month' => $this->singleCurrencyTotal($revenueByCurrency),
            'plan_distribution' => $this->planDistribution(),
        ];
    }

    /** Upper-case code; '' when unknown (an old payment or plan with no currency). */
    protected function currencyCode(?string $currency): string
    {
        return strtoupper(trim((string) $currency));
    }

    /**
     * @param  \Illuminate\Support\Collection<string, float>  $totals
     * @return list<array{currency: ?string, amount: float}>
     */
    protected function perCurrency($totals): array
    {
        return $totals
            ->map(fn (float $amount, string $currency) => ['currency' => $currency === '' ? null : $currency, 'amount' => round($amount, 2)])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, float>  $totals
     */
    protected function singleCurrencyTotal($totals): ?float
    {
        return match ($totals->count()) {
            0 => 0.0,
            1 => round((float) $totals->first(), 2),
            default => null,
        };
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
