<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use App\Models\Country;
use App\Services\General\AuditLogger;
use App\Services\Notifications\NotificationCenter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelancePlan;
use Modules\Freelance\Models\FreelancePlanPrice;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\FreelanceTeamSubscription as Subscription;
use Modules\Freelance\Models\FreelanceTeamSubscriptionInvoice as Invoice;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Policy\FreelanceLedger;
use Modules\Freelance\Services\Policy\FreelanceMoney;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletService;

/**
 * FS-211: a freelancers' team's plan - Team Starter and the ones after it. The same life as a freelancer's plan (`FreelanceSubscriptions`) with
 * the team as the subject, and who pays made explicit (D7): the Owner who takes the plan pays for it, out of his own wallet (the spend-only part
 * first), every charge - paid or failed - leaves an invoice, and the money is booked as income of the platform.
 *
 *   - Take: a trial the first time when the plan has one (nothing charged), else the price at once. Only an Owner takes a plan, and he becomes
 *     the payer. A plan that opens no more room than the free baseline (`TeamPlans::raises`) is not sold.
 *   - Upgrade (a better plan, or monthly -> yearly): at once, the unused time taken off, a new period from now, never a refund.
 *   - Downgrade (a lower plan, or yearly -> monthly): waits for the end of the period; until then nothing changes. Lower room never cuts what is
 *     already there (a person stays, a running project goes on): it only stops what is new.
 *   - Cancel: any Owner turns auto-renew off; the plan runs to the end of the period. Resume, upgrade, downgrade and "pay again" are the
 *     payer's, because they spend his wallet.
 *   - Renewal (`freelance:renew-subscriptions`, with the freelancers' own): charges the payer's wallet at the price of the day. Not enough in it
 *     -> the plan stays for the grace days (one retry a day), then it ends and the team is back on the free baseline. If the payer is no longer
 *     an Owner of the team it ends at the renewal, because nobody else agreed to pay.
 */
class TeamSubscriptions
{
    public function __construct(
        private readonly TeamPlans $plans,
        private readonly FreelancerTeams $teams,
        private readonly WalletService $wallets,
        private readonly FreelanceLedger $ledger,
        private readonly FreelanceMoney $money,
        private readonly NotificationCenter $notifications,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ who may

    /**
     * The team for one of its Owners (the plan is theirs to read and change). 404 when the team is not his, 403 team_plan_forbidden when he is
     * in it but not as an Owner.
     *
     * @throws ModelNotFoundException
     * @throws FreelanceException
     */
    public function forOwner(string $teamUuid, Freelancer $who): FreelanceTeam
    {
        $team = FreelanceTeam::query()->where('uuid', $teamUuid)->first();
        if ($team === null || ! $this->teams->isMember($team, $who)) {
            throw new ModelNotFoundException;
        }
        if (! $this->teams->isOwner($team, $who)) {
            throw new FreelanceException('team_plan_forbidden', 403);
        }

        return $team;
    }

    // ------------------------------------------------------------------ what the app shows

    /**
     * The plans on sale for this team with their prices, what each opens, and which one the team is on. The first entry is always the free
     * baseline (what the team has with no plan).
     *
     * @return array{currency: string, currency_symbol: string, decimals: int, plans: list<array<string, mixed>>}
     */
    public function catalog(FreelanceTeam $team): array
    {
        $out = $this->money->meta() + ['plans' => []];
        $current = $this->plans->plan($team);
        $used = Subscription::query()->where('team_id', $team->id)->exists();

        $out['plans'][] = [
            'slug' => FreelancePlan::FREE, 'name' => __('freelance.team_plans.free_name'), 'description' => __('freelance.team_plans.free_description'),
            'rank' => 0, 'free' => true, 'current' => $current === null, 'prices' => (object) [], 'yearly_saving_minor' => null,
            'benefits' => $this->benefits($this->plans->limitsFor($team, null)), 'trial_days' => 0,
        ];
        foreach (FreelancePlan::query()->forTeams()->where('status', true)->orderBy('rank')->get() as $plan) {
            $prices = $this->pricesOf($plan);
            if ($prices === [] || ! $this->plans->raises($team, $plan)) {
                continue;
            }
            $out['plans'][] = [
                'slug' => $plan->slug, 'name' => $plan->text('name'), 'description' => $plan->text('description'), 'rank' => $plan->rank, 'free' => false,
                'current' => $current?->id === $plan->id, 'prices' => $prices,
                'yearly_saving_minor' => isset($prices['monthly'], $prices['yearly']) ? max(0, $prices['monthly'] * 12 - $prices['yearly']) : null,
                'benefits' => $this->benefits($this->plans->limitsFor($team, $plan)),
                'trial_days' => ! $used ? (int) $plan->trial_days : 0,
            ];
        }

        return $out;
    }

    /** @return array{members_max: ?int, projects_max: ?int, offers_monthly: ?int} what a plan opens, `null` = no cap */
    private function benefits(array $limits): array
    {
        return ['members_max' => $limits['members'], 'projects_max' => $limits['projects'], 'offers_monthly' => $limits['offers']];
    }

    /** @return array<string, mixed>|null the team's subscription (the live one, else the last) */
    public function mine(FreelanceTeam $team, ?Freelancer $viewer = null): ?array
    {
        $sub = Subscription::query()->where('team_id', $team->id)->with(['plan', 'pendingPlan', 'payer'])->orderByRaw("status in ('trialing','active','past_due') desc")->latest('id')->first();

        return $sub === null ? null : $this->present($sub, $viewer);
    }

    /** @return array<string, mixed> */
    public function present(Subscription $s, ?Freelancer $viewer = null): array
    {
        return [
            'uuid' => $s->uuid,
            'plan' => ['slug' => $s->plan->slug, 'name' => $s->plan->text('name'), 'rank' => $s->plan->rank],
            'period' => $s->period,
            'status' => $s->status,
            'active' => $s->gives(),
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
            // Who pays: the Owner whose wallet it comes out of (D7). `paid_by_me` tells the app whether the buttons that spend are his.
            'paid_by' => $s->payer === null ? null : ['name' => $s->payer->name],
            'paid_by_me' => $viewer !== null && $viewer->id === $s->paid_by_freelancer_id,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function invoices(FreelanceTeam $team, int $limit = 30): array
    {
        return Invoice::query()->where('team_id', $team->id)->with('plan')->latest('id')->limit($limit)->get()->map(fn (Invoice $i) => [
            'uuid' => $i->uuid, 'plan' => $i->plan->text('name'), 'period' => $i->period, 'kind' => $i->kind, 'status' => $i->status,
            'price_minor' => $i->price_minor, 'credit_minor' => $i->credit_minor, 'amount_minor' => $i->amount_minor, 'currency' => $i->currency_code,
            'failure_code' => $i->failure_code, 'period_start' => $i->period_start?->toIso8601String(), 'period_end' => $i->period_end?->toIso8601String(),
            'paid_at' => $i->paid_at?->toIso8601String(), 'created_at' => $i->created_at?->toIso8601String(),
        ])->all();
    }

    // ------------------------------------------------------------------ what the Owner does

    /** What the app's one button does: a plan when none is live, else a change. @throws FreelanceException */
    public function take(FreelanceTeam $team, Freelancer $by, string $planSlug, string $period): Subscription
    {
        return $this->liveOf($team) === null ? $this->subscribe($team, $by, $planSlug, $period) : $this->change($team, $by, $planSlug, $period);
    }

    /**
     * Takes a plan for the team: a trial the first time when the plan has one, else it is bought now. 409 team_subscription_exists when one is
     * live, 409 freelancer_team_inactive, 422 plan_unavailable, 422 subscription_insufficient (the Owner's wallet).
     *
     * @throws FreelanceException
     */
    public function subscribe(FreelanceTeam $team, Freelancer $by, string $planSlug, string $period): Subscription
    {
        [$plan, $price] = $this->target($team, $planSlug, $period);

        $sub = DB::transaction(function () use ($team, $by, $plan, $period, $price) {
            $this->lockTeam($team);
            if ($this->liveOf($team) !== null) {
                throw new FreelanceException('team_subscription_exists', 409);
            }

            $now = now();
            $trial = $plan->trial_days > 0 && ! Subscription::query()->where('team_id', $team->id)->exists();
            $sub = Subscription::query()->create([
                'team_id' => $team->id, 'paid_by_freelancer_id' => $by->id, 'plan_id' => $plan->id, 'country_id' => $team->country_id, 'currency_code' => $this->money->code(),
                'period' => $period, 'price_minor' => $price, 'status' => $trial ? Subscription::TRIALING : Subscription::ACTIVE, 'auto_renew' => true,
                'started_at' => $now, 'period_start' => $now, 'period_end' => $trial ? $now->copy()->addDays($plan->trial_days) : $this->addPeriod($now, $period),
                'trial_ends_at' => $trial ? $now->copy()->addDays($plan->trial_days) : null,
            ]);
            if (! $trial) {
                $this->charge($sub, $team, $plan, $period, 'purchase', $price, 0, $now, $sub->period_end);
            }

            return $sub;
        });

        $this->audit->record('freelance.team.plan_taken', $team, null, ['plan' => $plan->slug, 'period' => $period, 'by' => $by->id]);
        $this->tell($sub, 'started');

        return $sub->load('plan');
    }

    /**
     * Another plan or period while one is live (the payer's). Better: at once, the unused time taken off. Lower: at the end of the period.
     * Choosing what is already waiting, or the current plan again while something waits, takes the waiting change back.
     *
     * @throws FreelanceException
     */
    public function change(FreelanceTeam $team, Freelancer $by, string $planSlug, string $period): Subscription
    {
        [$plan, $price] = $this->target($team, $planSlug, $period);

        $upgraded = false;
        try {
            $sub = DB::transaction(function () use ($team, $by, $plan, $period, $price, &$upgraded) {
                $this->lockTeam($team);
                $sub = $this->liveOf($team, true) ?? throw new FreelanceException('team_subscription_none', 404);
                $this->assertPayer($sub, $by);
                if ($sub->status === Subscription::PAST_DUE) {
                    throw new FreelanceException('team_subscription_past_due', 409);
                }
                $same = $sub->plan_id === $plan->id && $sub->period === $period;
                if ($same) {
                    if ($sub->pending_plan_id === null) {
                        throw new FreelanceException('team_subscription_unchanged', 409);
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
                $this->charge($sub, $team, $plan, $period, 'upgrade', max(0, $price - $credit), min($credit, $price), $now, $this->addPeriod($now, $period), $price);
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
                $this->recordFailure($team, $plan, $period, 'upgrade', $price, $e, $this->liveOf($team));
            }
            throw $e;
        }

        $this->audit->record('freelance.team.plan_changed', $team, null, ['plan' => $plan->slug, 'period' => $period, 'now' => $upgraded, 'by' => $by->id]);
        if ($upgraded) {
            $this->tell($sub, 'started');
        }

        return $sub->load(['plan', 'pendingPlan']);
    }

    /** Auto-renew off (any Owner): the plan runs to the end of the period, then the team is back on the free baseline. @throws FreelanceException */
    public function cancel(FreelanceTeam $team, Freelancer $by): Subscription
    {
        $sub = DB::transaction(function () use ($team) {
            $this->lockTeam($team);
            $sub = $this->liveOf($team, true) ?? throw new FreelanceException('team_subscription_none', 404);
            $sub->update(['auto_renew' => false, 'canceled_at' => now(), 'pending_plan_id' => null, 'pending_period' => null]);
            if ($sub->status === Subscription::PAST_DUE) {
                // Nothing was paid for this period: it ends now.
                $this->end($sub, notify: false);

                return $sub->refresh();
            }

            return $sub;
        });
        $this->audit->record('freelance.team.plan_canceled', $team, null, ['by' => $by->id]);

        return $sub->load('plan');
    }

    /** Auto-renew back on, before the period ends (the payer's). @throws FreelanceException */
    public function resume(FreelanceTeam $team, Freelancer $by): Subscription
    {
        return DB::transaction(function () use ($team, $by) {
            $this->lockTeam($team);
            $sub = $this->liveOf($team, true) ?? throw new FreelanceException('team_subscription_none', 404);
            $this->assertPayer($sub, $by);
            if ($sub->auto_renew || ! $sub->period_end->isFuture()) {
                throw new FreelanceException('team_subscription_not_resumable', 409);
            }
            $sub->update(['auto_renew' => true, 'canceled_at' => null]);

            return $sub->load('plan');
        });
    }

    /** A failed renewal tried again now (the payer topped up the wallet). @throws FreelanceException */
    public function renewNow(FreelanceTeam $team, Freelancer $by): Subscription
    {
        $sub = $this->liveOf($team);
        if ($sub === null || $sub->status !== Subscription::PAST_DUE) {
            throw new FreelanceException('team_subscription_not_resumable', 409);
        }
        $this->assertPayer($sub, $by);
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
                $this->tell($sub, 'renewing');
                $out['reminded']++;
            });

        return $out;
    }

    /**
     * One subscription's turn: ends when it will not renew or nobody is left to pay, renews when the payer's wallet pays, else waits out the grace days.
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
                // The one who pays is not an Owner of the team any more (he left, was removed, or stepped down): nobody else agreed to pay.
                $team = FreelanceTeam::query()->find($sub->team_id);
                if ($team === null || ! $this->teams->isOwner($team, Freelancer::query()->findOrFail($sub->paid_by_freelancer_id))) {
                    $this->end($sub, event: 'payer_left', notify: $team !== null);
                    $result = 'ended';

                    return;
                }
                // One retry a day while it waits (unless the payer asked for it).
                if ($past && ! $force && $sub->last_attempt_at !== null && $sub->last_attempt_at->gt(now()->subHours(23))) {
                    return;
                }

                $plan = $sub->pending_plan_id !== null ? FreelancePlan::query()->find($sub->pending_plan_id) : $sub->plan;
                $period = $sub->pending_period ?? $sub->period;
                $price = $plan !== null && $plan->status ? ($this->pricesOf($plan)[$period] ?? null) : null;
                if ($plan === null || $price === null) {
                    // The plan or its price is gone: nothing to renew into.
                    $this->end($sub);
                    $result = 'ended';

                    return;
                }

                $from = $past ? now() : $sub->period_end;
                $sub->update(['last_attempt_at' => now()]);
                $this->charge($sub, $team, $plan, $period, 'renewal', $price, 0, $from, $this->addPeriod($from, $period));
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
            $this->tell($tell->refresh(), 'renewed');
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
        $team = FreelanceTeam::query()->withTrashed()->find($sub->team_id);
        if ($team !== null) {
            $this->recordFailure($team, $plan ?? $sub->plan, $period, 'renewal', (int) ($this->pricesOf($plan ?? $sub->plan)[$period] ?? $sub->price_minor), $e, $sub);
        }
        if ($first) {
            $this->tell($sub, 'renewal_failed');
        }

        return 'failed';
    }

    /** The plan ends: the team is back on the free baseline (and its Owners told, unless the Owner ended it himself). */
    private function end(Subscription $sub, string $event = 'expired', bool $notify = true): void
    {
        $sub->update(['status' => Subscription::EXPIRED, 'ended_at' => now(), 'auto_renew' => false, 'pending_plan_id' => null, 'pending_period' => null, 'grace_until' => null]);
        if ($notify) {
            $this->tell($sub, $event);
        }
    }

    /**
     * Takes the money from the payer's wallet and writes the paid invoice (inside the caller's transaction, so a refusal leaves nothing).
     *
     * @param  int  $amount  what is charged
     * @param  int  $credit  the unused time taken off (shown on the invoice)
     * @param  int|null  $price  the plan's price (defaults to amount + credit)
     *
     * @throws FreelanceException subscription_insufficient
     */
    private function charge(Subscription $sub, FreelanceTeam $team, FreelancePlan $plan, string $period, string $kind, int $amount, int $credit, CarbonInterface $from, CarbonInterface $to, ?int $price = null): Invoice
    {
        $country = Country::query()->with('currency')->findOrFail($sub->country_id);
        $payer = $sub->payer ?? Freelancer::query()->findOrFail($sub->paid_by_freelancer_id);
        $uuid = (string) Str::uuid();
        $label = $plan->text('name').' - '.$team->name.' ('.__('freelance.plan_periods.'.$period).')';
        $transaction = null;

        if ($amount > 0) {
            $wallet = $this->money->wallet($payer);
            $available = $wallet->spendableMinor();
            if ($available < $amount) {
                throw new FreelanceException('subscription_insufficient', 422, [
                    'needed' => $this->format($amount), 'available' => $this->format($available),
                ], ['needed_minor' => $amount, 'available_minor' => $available, 'shortfall_minor' => $amount - $available, 'currency' => $this->money->code()]);
            }
            $transaction = $this->wallets->debitSpendOnlyFirst($wallet, $amount, WalletTransactionType::ServicePayment, [
                'reference_type' => 'freelance_team_subscription', 'reference_id' => $sub->id, 'idempotency_key' => 'freelance:team_subscription:'.$uuid,
                'notes' => ['key' => 'wallet.notes.freelance_subscription', 'variables' => ['plan' => $label]],
            ]);
        }

        $invoice = Invoice::query()->create([
            'uuid' => $uuid, 'team_subscription_id' => $sub->id, 'team_id' => $team->id, 'paid_by_freelancer_id' => $payer->id, 'plan_id' => $plan->id, 'period' => $period,
            'kind' => $kind, 'price_minor' => $price ?? ($amount + $credit), 'credit_minor' => $credit, 'amount_minor' => $amount, 'currency_code' => $this->money->code(),
            'status' => Invoice::PAID, 'wallet_transaction_id' => $transaction?->id, 'period_start' => $from, 'period_end' => $to, 'paid_at' => now(),
        ]);
        if ($transaction instanceof WalletTransaction) {
            $this->ledger->subscription($amount, $this->money->currency(), $country, $invoice, $label, $transaction);
        }

        return $invoice;
    }

    private function recordFailure(FreelanceTeam $team, FreelancePlan $plan, string $period, string $kind, int $price, FreelanceException $e, ?Subscription $sub = null): void
    {
        if ($sub === null) {
            // A purchase that never became a subscription has no row to hang the invoice on: nothing is kept.
            return;
        }
        Invoice::query()->create([
            'team_subscription_id' => $sub->id, 'team_id' => $team->id, 'paid_by_freelancer_id' => $sub->paid_by_freelancer_id, 'plan_id' => $plan->id, 'period' => $period,
            'kind' => $kind, 'price_minor' => $price, 'credit_minor' => 0, 'amount_minor' => $price, 'currency_code' => $this->money->code(), 'status' => Invoice::FAILED,
            'failure_code' => $e->errorCode,
        ]);
    }

    /**
     * @return array{0: FreelancePlan, 1: int} the plan and the price the team would pay
     *
     * @throws FreelanceException
     */
    private function target(FreelanceTeam $team, string $planSlug, string $period): array
    {
        if (! $team->isActive()) {
            throw new FreelanceException('freelancer_team_inactive', 409);
        }
        if (! in_array($period, FreelancePlanPrice::PERIODS, true)) {
            throw new FreelanceException('plan_unavailable', 422);
        }
        $plan = FreelancePlan::query()->forTeams()->where('slug', $planSlug)->where('status', true)->first();
        $price = $plan === null ? null : ($this->pricesOf($plan)[$period] ?? null);
        if ($plan === null || $price === null || ! $this->plans->raises($team, $plan)) {
            throw new FreelanceException('plan_unavailable', 422);
        }

        return [$plan, $price];
    }

    /** The payer's own wallet pays upgrades, resumes and retries, so they are his alone. 403 team_plan_payer_only. */
    private function assertPayer(Subscription $sub, Freelancer $by): void
    {
        if ($sub->paid_by_freelancer_id !== $by->id) {
            throw new FreelanceException('team_plan_payer_only', 403);
        }
    }

    /** @return array<string, int> period => price in minor units (active prices only). One price for the whole world, in the freelance currency. */
    private function pricesOf(FreelancePlan $plan): array
    {
        return FreelancePlanPrice::query()->where('plan_id', $plan->id)->where('country_id', $this->money->countryId())->where('status', true)->pluck('price_minor', 'period')->map(fn ($v) => (int) $v)->all();
    }

    private function isUpgrade(Subscription $sub, FreelancePlan $target, string $period): bool
    {
        $current = $sub->plan;
        if ($target->rank !== $current->rank) {
            return $target->rank > $current->rank;
        }

        return $sub->period === FreelancePlanPrice::MONTHLY && $period === FreelancePlanPrice::YEARLY;
    }

    /** What is left of the paid period, in money: the price x the time that remains / the length of the period. */
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

    private function liveOf(FreelanceTeam $team, bool $lock = false): ?Subscription
    {
        $query = Subscription::query()->live()->where('team_id', $team->id)->with('plan')->latest('id');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** One change at a time per team (a double tap, or the clock and an Owner together). */
    private function lockTeam(FreelanceTeam $team): void
    {
        FreelanceTeam::query()->whereKey($team->id)->lockForUpdate()->first();
    }

    private function format(int $minor): string
    {
        $decimals = $this->money->decimals();

        return number_format($minor / (10 ** $decimals), $decimals).' '.$this->money->code();
    }

    /**
     * Tells the payer (started, renewing, renewed, renewal failed) or every Owner of the team (it ended, or ended because the payer left).
     * `$event` is the short name; the notification is `freelance.team_subscription_{event}`.
     */
    private function tell(Subscription $sub, string $event): void
    {
        $sub->loadMissing(['plan', 'team', 'payer']);
        $team = $sub->team;
        if ($team === null || $sub->payer === null) {
            return;
        }
        $everyOwner = in_array($event, ['expired', 'payer_left'], true);
        $receivers = $everyOwner
            ? Freelancer::query()->whereIn('id', Member::query()->where('team_id', $team->id)->where('role', Member::OWNER)->where('status', Member::ACTIVE)->select('freelancer_id'))->get()
            : collect([$sub->payer]);
        $date = ($event === 'renewal_failed' ? ($sub->grace_until ?? $sub->period_end) : $sub->period_end)->toDateString();

        $this->notifications->send($receivers, 'freelance.team_subscription_'.$event, 'freelance_team_subscription_'.$event.'_title', 'freelance_team_subscription_'.$event.'_body',
            ['team' => $team->name, 'plan' => (string) $sub->plan->text('name'), 'date' => $date, 'amount' => $this->format($sub->price_minor)],
            ['type' => 'freelance_team_subscription_'.$event, 'team' => $team->uuid, 'subscription' => $sub->uuid]);
    }
}
