<?php

namespace Modules\Freelance\Services\Plans;

use App\Models\Country;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\FreelanceSubscription as Subscription;
use Modules\Freelance\Models\FreelanceSubscriptionInvoice as Invoice;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelanceLedger;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletService;

/**
 * Freelancer subscriptions (FL-L01): buy a plan, change it, cancel it, and the renewals. The money moves in the freelancer's own wallet (the
 * spend-only part first) and is booked as income of the platform; every charge — paid or failed — leaves an invoice.
 *
 *   - First time: a trial (`plan.trial_days`) with nothing charged; later, the price at once.
 *   - Upgrade (a better plan, or monthly → yearly): at once. The unused time of what was paid is taken off the new price and a new period
 *     starts now. Never a refund.
 *   - Downgrade (a lower plan, or yearly → monthly): waits for the end of the period; until then nothing changes.
 *   - Cancel: auto-renew goes off; the plan runs to the end of the period. Resume turns it on again before the end.
 *   - Renewal (`freelance:renew-subscriptions`): charges the price of the day. If the wallet has not enough the plan stays for the grace days
 *     (`freelance.plan_grace_days`, one retry a day), then ends and the freelancer is back on Free.
 */
class FreelanceSubscriptions
{
    public function __construct(
        private readonly FreelancePlans $plans,
        private readonly WalletService $wallets,
        private readonly FreelanceLedger $ledger,
        private readonly FreelanceNotifier $notifier,
    ) {}

    // ------------------------------------------------------------------ what the app shows

    /**
     * The plans of the freelancer's country with their prices, and which one they are on.
     *
     * @return array{enabled: bool, currency: ?string, decimals: int, plans: list<array<string, mixed>>}
     */
    public function catalog(Freelancer $freelancer): array
    {
        $country = $this->countryOf($freelancer);
        $enabled = $country !== null && $this->plans->enabled($country->id);
        $currency = app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->currency();
        $out = ['enabled' => $enabled, 'currency' => $currency?->code, 'currency_symbol' => $currency?->symbol, 'decimals' => (int) ($currency?->decimal_places ?? 2), 'plans' => []];
        if (! $enabled) {
            return $out;
        }

        $current = $this->plans->planFor($freelancer);
        $trialUsed = Subscription::query()->where('freelancer_id', $freelancer->id)->exists();
        $policy = app(\Modules\Freelance\Services\Policy\FreelancePolicies::class)->for($country->id);
        $policyQuota = (int) $policy->get('offers.free_monthly_quota');
        $policyServices = (int) ($policy->get('services.max_active') ?? 5);

        // FS-211: a team's plans are sold to its Owner for the team (`TeamSubscriptions`), never listed here.
        foreach (FreelancePlan::query()->forFreelancers()->where('status', true)->orderBy('rank')->get() as $plan) {
            $prices = $plan->isFree() ? [] : $this->pricesOf($plan, $country->id);
            if (! $plan->isFree() && $prices === []) {
                continue;
            }
            $out['plans'][] = [
                'slug' => $plan->slug,
                'name' => $plan->text('name'),
                'description' => $plan->text('description'),
                'rank' => $plan->rank,
                'free' => $plan->isFree(),
                'current' => $plan->slug === $current->slug,
                'prices' => $prices === [] ? (object) [] : $prices,
                'yearly_saving_minor' => isset($prices['monthly'], $prices['yearly']) ? max(0, $prices['monthly'] * 12 - $prices['yearly']) : null,
                'benefits' => [
                    'offers_unlimited' => $plan->offers_unlimited,
                    'offers_monthly' => $plan->offers_unlimited ? null : max($policyQuota, (int) ($plan->offers_monthly ?? $policyQuota)),
                    'commission_bp' => $plan->commission_bp,
                    // FL-L01 phase 4: the badge, the weight in the «recommended» order (0-3), the numbers (0 none, 1 basic, 2 full), and the ready-made services allowed at once.
                    'badge' => (bool) $plan->badge,
                    'boost' => (int) $plan->boost,
                    'analytics' => (int) $plan->analytics,
                    'services_max' => $plan->services_max === null ? $policyServices : max($policyServices, (int) $plan->services_max),
                ],
                'trial_days' => ! $plan->isFree() && ! $trialUsed ? (int) $plan->trial_days : 0,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null my subscription (the live one, else the last), with what is waiting */
    public function mine(Freelancer $freelancer): ?array
    {
        $sub = Subscription::query()->where('freelancer_id', $freelancer->id)->with(['plan', 'pendingPlan'])->orderByRaw("status in ('trialing','active','past_due') desc")->latest('id')->first();

        return $sub === null ? null : $this->present($sub);
    }

    /** @return array<string, mixed> */
    public function present(Subscription $s): array
    {
        $gives = $this->plans->gives($s);

        return [
            'uuid' => $s->uuid,
            'plan' => ['slug' => $s->plan->slug, 'name' => $s->plan->text('name'), 'rank' => $s->plan->rank],
            'period' => $s->period,
            'status' => $s->status,
            'active' => $gives,
            'auto_renew' => $s->auto_renew,
            'price_minor' => $s->price_minor,
            'currency' => $s->currency_code,
            'started_at' => $s->started_at?->toIso8601String(),
            'period_start' => $s->period_start?->toIso8601String(),
            'period_end' => $s->period_end?->toIso8601String(),
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'grace_until' => $s->grace_until?->toIso8601String(),
            'canceled_at' => $s->canceled_at?->toIso8601String(),
            'pending' => $s->pending_plan_id === null ? null : ['plan' => ['slug' => $s->pendingPlan?->slug, 'name' => $s->pendingPlan?->text('name')], 'period' => $s->pending_period],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function invoices(Freelancer $freelancer, int $limit = 30): array
    {
        return Invoice::query()->where('freelancer_id', $freelancer->id)->with('plan')->latest('id')->limit($limit)->get()->map(fn (Invoice $i) => [
            'uuid' => $i->uuid, 'plan' => $i->plan->text('name'), 'period' => $i->period, 'kind' => $i->kind, 'status' => $i->status,
            'price_minor' => $i->price_minor, 'credit_minor' => $i->credit_minor, 'amount_minor' => $i->amount_minor, 'currency' => $i->currency_code,
            'failure_code' => $i->failure_code, 'period_start' => $i->period_start?->toIso8601String(), 'period_end' => $i->period_end?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(), 'created_at' => $i->created_at?->toIso8601String(),
        ])->all();
    }

    // ------------------------------------------------------------------ what the freelancer does

    /** What the app's one button does: a plan when none is live, else a change. @throws FreelanceException */
    public function take(Freelancer $freelancer, string $planSlug, string $period): Subscription
    {
        return $this->liveOf($freelancer) === null ? $this->subscribe($freelancer, $planSlug, $period) : $this->change($freelancer, $planSlug, $period);
    }

    /**
     * Takes a plan. With none live: a trial the first time, else it is bought now. With one live: it is a change (see `change()`).
     *
     * @throws FreelanceException
     */
    public function subscribe(Freelancer $freelancer, string $planSlug, string $period): Subscription
    {
        [$plan, $country, $price] = $this->target($freelancer, $planSlug, $period);

        $sub = null;
        try {
            $sub = DB::transaction(function () use ($freelancer, $plan, $country, $period, $price) {
                $this->lockFreelancer($freelancer);
                if ($this->liveOf($freelancer) !== null) {
                    throw new FreelanceException('subscription_exists', 409);
                }

                $now = now();
                $trial = $plan->trial_days > 0 && ! Subscription::query()->where('freelancer_id', $freelancer->id)->exists();
                $sub = Subscription::query()->create([
                    'freelancer_id' => $freelancer->id, 'plan_id' => $plan->id, 'country_id' => $country->id, 'currency_code' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(),
                    'period' => $period, 'price_minor' => $price, 'status' => $trial ? Subscription::TRIALING : Subscription::ACTIVE, 'auto_renew' => true,
                    'started_at' => $now, 'period_start' => $now, 'period_end' => $trial ? $now->copy()->addDays($plan->trial_days) : $this->addPeriod($now, $period),
                    'trial_ends_at' => $trial ? $now->copy()->addDays($plan->trial_days) : null,
                ]);
                if (! $trial) {
                    $this->charge($sub, $plan, $period, 'purchase', $price, 0, $now, $sub->period_end);
                }

                return $sub;
            });
        } catch (FreelanceException $e) {
            throw $e;
        }

        $this->tell($sub, 'subscription.started');

        return $sub->load('plan');
    }

    /**
     * Another plan or period while one is live. Better: at once, with the unused time taken off. Lower: at the end of the period.
     * Choosing what is already waiting, or the current plan again while something waits, takes the waiting change back.
     *
     * @throws FreelanceException
     */
    public function change(Freelancer $freelancer, string $planSlug, string $period): Subscription
    {
        [$plan, $country, $price] = $this->target($freelancer, $planSlug, $period);

        $sub = null;
        $upgraded = false;
        try {
            $sub = DB::transaction(function () use ($freelancer, $plan, $country, $period, $price, &$upgraded) {
                $this->lockFreelancer($freelancer);
                $sub = $this->liveOf($freelancer, true);
                if ($sub === null) {
                    throw new FreelanceException('subscription_none', 404);
                }
                if ($sub->status === Subscription::PAST_DUE) {
                    throw new FreelanceException('subscription_past_due', 409);
                }
                $same = $sub->plan_id === $plan->id && $sub->period === $period;
                if ($same) {
                    if ($sub->pending_plan_id === null) {
                        throw new FreelanceException('subscription_unchanged', 409);
                    }
                    $sub->update(['pending_plan_id' => null, 'pending_period' => null]);

                    return $sub;
                }

                // A trial switches freely: nothing was paid.
                if ($sub->status === Subscription::TRIALING) {
                    $sub->update(['plan_id' => $plan->id, 'period' => $period, 'price_minor' => $price, 'pending_plan_id' => null, 'pending_period' => null, 'auto_renew' => true, 'canceled_at' => null]);

                    return $sub;
                }

                if (! $this->isUpgrade($sub, $plan, $period)) {
                    $sub->update(['pending_plan_id' => $plan->id, 'pending_period' => $period, 'auto_renew' => true, 'canceled_at' => null]);

                    return $sub;
                }

                $now = now();
                $credit = $this->unusedCredit($sub, $now);
                $this->charge($sub, $plan, $period, 'upgrade', max(0, $price - $credit), min($credit, $price), $now, $this->addPeriod($now, $period), $price);
                $sub->update([
                    'plan_id' => $plan->id, 'period' => $period, 'price_minor' => $price, 'status' => Subscription::ACTIVE, 'auto_renew' => true, 'canceled_at' => null,
                    'period_start' => $now, 'period_end' => $this->addPeriod($now, $period), 'pending_plan_id' => null, 'pending_period' => null,
                    'grace_until' => null, 'reminded_at' => null,
                ]);
                $upgraded = true;

                return $sub;
            });
        } catch (FreelanceException $e) {
            if ($e->errorCode === 'subscription_insufficient') {
                $this->recordFailure($freelancer, $plan, $country, $period, 'upgrade', $price, $e, $this->liveOf($freelancer));
            }
            throw $e;
        }

        if ($upgraded) {
            $this->tell($sub, 'subscription.started');
        }

        return $sub->load(['plan', 'pendingPlan']);
    }

    /** Auto-renew off: the plan runs to the end of the period, then the freelancer is on Free. @throws FreelanceException */
    public function cancel(Freelancer $freelancer): Subscription
    {
        return DB::transaction(function () use ($freelancer) {
            $this->lockFreelancer($freelancer);
            $sub = $this->liveOf($freelancer, true) ?? throw new FreelanceException('subscription_none', 404);
            if ($sub->status === Subscription::PAST_DUE) {
                // Nothing was paid for this period: it ends now.
                $sub->update(['auto_renew' => false, 'canceled_at' => now(), 'pending_plan_id' => null, 'pending_period' => null]);
                $this->end($sub, notify: false);

                return $sub->refresh()->load('plan');
            }
            $sub->update(['auto_renew' => false, 'canceled_at' => now(), 'pending_plan_id' => null, 'pending_period' => null]);

            return $sub->load('plan');
        });
    }

    /** Auto-renew back on, before the period ends. @throws FreelanceException */
    public function resume(Freelancer $freelancer): Subscription
    {
        return DB::transaction(function () use ($freelancer) {
            $this->lockFreelancer($freelancer);
            $sub = $this->liveOf($freelancer, true) ?? throw new FreelanceException('subscription_none', 404);
            if ($sub->auto_renew || ! $sub->period_end->isFuture()) {
                throw new FreelanceException('subscription_not_resumable', 409);
            }
            $sub->update(['auto_renew' => true, 'canceled_at' => null]);

            return $sub->load('plan');
        });
    }

    /** A failed renewal tried again now (the freelancer topped up the wallet). @throws FreelanceException */
    public function renewNow(Freelancer $freelancer): Subscription
    {
        $sub = $this->liveOf($freelancer);
        if ($sub === null || $sub->status !== Subscription::PAST_DUE) {
            throw new FreelanceException('subscription_not_resumable', 409);
        }
        $this->renew($sub, force: true);

        $fresh = $sub->refresh()->load('plan');
        if ($fresh->status === Subscription::PAST_DUE) {
            throw new FreelanceException('subscription_insufficient', 422);
        }

        return $fresh;
    }

    // ------------------------------------------------------------------ the clock (freelance:renew-subscriptions)

    /**
     * One pass: renew or end what is due, retry what failed, remind what renews soon.
     *
     * @return array{renewed: int, failed: int, ended: int, reminded: int}
     */
    public function run(): array
    {
        $out = ['renewed' => 0, 'failed' => 0, 'ended' => 0, 'reminded' => 0];

        Subscription::query()->live()->where(fn ($q) => $q->where('period_end', '<=', now())->orWhere('status', Subscription::PAST_DUE))
            ->eachById(function (Subscription $sub) use (&$out) {
                $before = $sub->status;
                $result = $this->renew($sub);
                if ($result === 'renewed') {
                    $out['renewed']++;
                } elseif ($result === 'ended') {
                    $out['ended']++;
                } elseif ($result === 'failed' && $before !== Subscription::PAST_DUE) {
                    $out['failed']++;
                }
            });

        $until = now()->addDays((int) config('freelance.plan_remind_days', 3));
        Subscription::query()->whereIn('status', [Subscription::TRIALING, Subscription::ACTIVE])->where('auto_renew', true)->whereNull('reminded_at')
            ->where('period_end', '>', now())->where('period_end', '<=', $until)->eachById(function (Subscription $sub) use (&$out) {
                $sub->update(['reminded_at' => now()]);
                $this->tell($sub, 'subscription.renewing');
                $out['reminded']++;
            });

        return $out;
    }

    /**
     * One subscription's turn: ends when it will not renew, renews when the wallet pays, else waits out the grace days.
     *
     * @return 'renewed'|'ended'|'failed'|'skipped'
     */
    public function renew(Subscription $subscription, bool $force = false): string
    {
        $failure = null;
        $result = 'skipped';
        $tell = null;

        try {
            DB::transaction(function () use ($subscription, $force, &$result, &$tell) {
                $sub = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->first();
                if ($sub === null || ! $sub->isLive()) {
                    return;
                }
                $past = $sub->status === Subscription::PAST_DUE;
                if (! $past && $sub->period_end->isFuture()) {
                    return;
                }
                // The grace days are over, or nothing asks for another period: it ends.
                if (($past && ($sub->grace_until === null || $sub->grace_until->lte(now()))) || (! $sub->auto_renew && $sub->pending_plan_id === null)) {
                    $this->end($sub);
                    $result = 'ended';

                    return;
                }
                // One retry a day while it waits (unless the freelancer asked for it).
                if ($past && ! $force && $sub->last_attempt_at !== null && $sub->last_attempt_at->gt(now()->subHours(23))) {
                    return;
                }

                $plan = $sub->pending_plan_id !== null ? FreelancePlan::query()->find($sub->pending_plan_id) : $sub->plan;
                $period = $sub->pending_period ?? $sub->period;
                $price = $plan !== null && $plan->status ? ($this->pricesOf($plan, $sub->country_id)[$period] ?? null) : null;
                if ($plan === null || $price === null) {
                    // The plan or its price is gone: nothing to renew into.
                    $this->end($sub);
                    $result = 'ended';

                    return;
                }

                $from = $past ? now() : $sub->period_end;
                $sub->update(['last_attempt_at' => now()]);
                $this->charge($sub, $plan, $period, 'renewal', $price, 0, $from, $this->addPeriod($from, $period));
                $sub->update([
                    'plan_id' => $plan->id, 'period' => $period, 'price_minor' => $price, 'status' => Subscription::ACTIVE, 'period_start' => $from,
                    'period_end' => $this->addPeriod($from, $period), 'pending_plan_id' => null, 'pending_period' => null, 'grace_until' => null, 'reminded_at' => null,
                ]);
                $result = 'renewed';
                $tell = $sub;
            });
        } catch (FreelanceException $e) {
            if ($e->errorCode !== 'subscription_insufficient') {
                throw $e;
            }
            $failure = $e;
        }

        if ($failure !== null) {
            return $this->failedRenewal($subscription->id, $failure);
        }
        if ($tell !== null) {
            $this->tell($tell->refresh(), 'subscription.renewed');
        }

        return $result;
    }

    // ------------------------------------------------------------------ inside

    /** A renewal the wallet could not pay: the plan stays for the grace days (told once), the failed charge is kept as an invoice. */
    private function failedRenewal(int $id, FreelanceException $e): string
    {
        $first = false;
        $sub = DB::transaction(function () use ($id, &$first) {
            $sub = Subscription::query()->whereKey($id)->lockForUpdate()->first();
            if ($sub === null || ! $sub->isLive()) {
                return null;
            }
            $first = $sub->status !== Subscription::PAST_DUE;
            $sub->update([
                'status' => Subscription::PAST_DUE, 'last_attempt_at' => now(),
                'grace_until' => $sub->grace_until ?? now()->addDays((int) config('freelance.plan_grace_days', 3)),
            ]);

            return $sub;
        });
        if ($sub === null) {
            return 'skipped';
        }

        $plan = $sub->pending_plan_id !== null ? FreelancePlan::query()->find($sub->pending_plan_id) : $sub->plan;
        $period = $sub->pending_period ?? $sub->period;
        $this->recordFailure($sub->freelancer, $plan ?? $sub->plan, $sub->country, $period, 'renewal', (int) ($this->pricesOf($plan ?? $sub->plan, $sub->country_id)[$period] ?? $sub->price_minor), $e, $sub);
        if ($first) {
            $this->tell($sub, 'subscription.renewal_failed');
        }

        return 'failed';
    }

    /** The plan ends: the freelancer is back on Free (and told, unless they ended it themselves). */
    private function end(Subscription $sub, bool $notify = true): void
    {
        $sub->update(['status' => Subscription::EXPIRED, 'ended_at' => now(), 'auto_renew' => false, 'pending_plan_id' => null, 'pending_period' => null, 'grace_until' => null]);
        if ($notify) {
            $this->tell($sub, 'subscription.expired');
        }
    }

    /**
     * Takes the money and writes the paid invoice (inside the caller's transaction, so a refusal leaves nothing). The wallet is the
     * freelancer's in the subscription's country; the spend-only part goes first.
     *
     * @param  int  $amount  what is charged
     * @param  int  $credit  the unused time taken off (shown on the invoice)
     * @param  int|null  $price  the plan's price (defaults to amount + credit)
     *
     * @throws FreelanceException subscription_insufficient
     */
    private function charge(Subscription $sub, FreelancePlan $plan, string $period, string $kind, int $amount, int $credit, CarbonInterface $from, CarbonInterface $to, ?int $price = null): Invoice
    {
        $country = Country::query()->with('currency')->findOrFail($sub->country_id);
        $freelancer = $sub->freelancer ?? Freelancer::query()->findOrFail($sub->freelancer_id);
        $uuid = (string) Str::uuid();
        $transaction = null;

        if ($amount > 0) {
            $wallet = app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->wallet($freelancer);
            $available = $wallet->spendableMinor();
            if ($available < $amount) {
                throw new FreelanceException('subscription_insufficient', 422, [
                    'needed' => $this->money($amount, $country), 'available' => $this->money($available, $country),
                ], ['needed_minor' => $amount, 'available_minor' => $available, 'shortfall_minor' => $amount - $available, 'currency' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code()]);
            }
            $label = $plan->text('name').' ('.__('freelance.plan_periods.'.$period).')';
            $transaction = $this->wallets->debitSpendOnlyFirst($wallet, $amount, WalletTransactionType::ServicePayment, [
                'reference_type' => 'freelance_subscription', 'reference_id' => $sub->id, 'idempotency_key' => 'freelance:subscription:'.$uuid,
                'notes' => ['key' => 'wallet.notes.freelance_subscription', 'variables' => ['plan' => $label]],
            ]);
        }

        $invoice = Invoice::query()->create([
            'uuid' => $uuid, 'subscription_id' => $sub->id, 'freelancer_id' => $freelancer->id, 'plan_id' => $plan->id, 'period' => $period,
            'kind' => $kind, 'price_minor' => $price ?? ($amount + $credit), 'credit_minor' => $credit, 'amount_minor' => $amount, 'currency_code' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(),
            'status' => Invoice::PAID, 'wallet_transaction_id' => $transaction?->id, 'period_start' => $from, 'period_end' => $to, 'paid_at' => now(),
        ]);
        if ($transaction instanceof WalletTransaction) {
            $this->ledger->subscription($amount, app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->currency(), $country, $invoice, (string) $plan->text('name'), $transaction);
        }

        return $invoice;
    }

    private function recordFailure(Freelancer $freelancer, FreelancePlan $plan, ?Country $country, string $period, string $kind, int $price, FreelanceException $e, ?Subscription $sub = null): void
    {
        if ($sub === null) {
            // A purchase that never became a subscription has no subscription row to hang the invoice on: nothing is kept.
            return;
        }
        Invoice::query()->create([
            'subscription_id' => $sub->id, 'freelancer_id' => $freelancer->id, 'plan_id' => $plan->id, 'period' => $period, 'kind' => $kind, 'price_minor' => $price,
            'credit_minor' => 0, 'amount_minor' => $price, 'currency_code' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(), 'status' => Invoice::FAILED,
            'failure_code' => $e->errorCode,
        ]);
    }

    /** @return array{0: FreelancePlan, 1: Country, 2: int} the plan, the freelancer's country and the price they would pay */
    private function target(Freelancer $freelancer, string $planSlug, string $period): array
    {
        $country = $this->countryOf($freelancer);
        if ($country === null || ! $this->plans->enabled($country->id)) {
            throw new FreelanceException('subscriptions_closed', 403);
        }
        if (! in_array($period, FreelancePlanPrice::PERIODS, true)) {
            throw new FreelanceException('plan_unavailable', 422);
        }
        $plan = FreelancePlan::query()->forFreelancers()->where('slug', $planSlug)->where('status', true)->first();
        $price = $plan === null || $plan->isFree() ? null : ($this->pricesOf($plan, $country->id)[$period] ?? null);
        if ($plan === null || $price === null) {
            throw new FreelanceException('plan_unavailable', 422);
        }

        return [$plan, $country, $price];
    }

    /** @return array<string, int> period => price in minor units (active prices only) */
    /** One price for the whole world, in the freelance currency (`freelance.money`): the rows on the international row. */
    private function pricesOf(FreelancePlan $plan, ?int $countryId = null): array
    {
        return FreelancePlanPrice::query()->where('plan_id', $plan->id)->where('country_id', app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->countryId())->where('status', true)->pluck('price_minor', 'period')->map(fn ($v) => (int) $v)->all();
    }

    private function isUpgrade(Subscription $sub, FreelancePlan $target, string $period): bool
    {
        $current = $sub->plan;
        if ($target->rank !== $current->rank) {
            return $target->rank > $current->rank;
        }

        return $sub->period === FreelancePlanPrice::MONTHLY && $period === FreelancePlanPrice::YEARLY;
    }

    /** What is left of the paid period, in money: the price × the time that remains / the length of the period. */
    private function unusedCredit(Subscription $sub, CarbonInterface $now): int
    {
        $total = max(1, $sub->period_end->getTimestamp() - $sub->period_start->getTimestamp());
        $left = max(0, min($total, $sub->period_end->getTimestamp() - $now->getTimestamp()));

        return intdiv($sub->price_minor * $left, $total);
    }

    private function addPeriod(CarbonInterface $from, string $period): CarbonInterface
    {
        return $period === FreelancePlanPrice::YEARLY ? $from->copy()->addYearNoOverflow() : $from->copy()->addMonthNoOverflow();
    }

    private function liveOf(Freelancer $freelancer, bool $lock = false): ?Subscription
    {
        $query = Subscription::query()->live()->where('freelancer_id', $freelancer->id)->with('plan')->latest('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** One change at a time per freelancer (a double tap, or the clock and the freelancer together). */
    private function lockFreelancer(Freelancer $freelancer): void
    {
        Freelancer::query()->whereKey($freelancer->id)->lockForUpdate()->first();
    }

    private function countryOf(Freelancer $freelancer): ?Country
    {
        return $freelancer->country_id === null ? null : Country::query()->with('currency')->find($freelancer->country_id);
    }

    private function money(int $minor, Country $country): string
    {
        $decimals = app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->decimals();

        return number_format($minor / (10 ** $decimals), $decimals).' '.app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code();
    }

    private function tell(Subscription $sub, string $event): void
    {
        $sub->loadMissing(['plan', 'freelancer', 'country.currency']);
        $country = $sub->country;
        $vars = ['plan' => (string) $sub->plan->text('name'), 'date' => $sub->period_end->toDateString(), 'amount' => $country === null ? '' : $this->money($sub->price_minor, $country)];
        if ($event === 'subscription.renewal_failed') {
            $vars['date'] = ($sub->grace_until ?? $sub->period_end)->toDateString();
        }
        $this->notifier->notify($sub->freelancer, $event, $vars, ['subscription' => $sub->uuid]);
    }
}
