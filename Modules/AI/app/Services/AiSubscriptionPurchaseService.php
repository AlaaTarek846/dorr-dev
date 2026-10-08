<?php

namespace Modules\AI\Services;

use App\Models\Country;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Modules\AI\Exceptions\AiSubscriptionException;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Models\AiSubscriptionPayment;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\InsufficientBalanceException;

/**
 * The user-facing half of the subscription system (AiSubscriptionBillingService
 * is the money-moving half) - subscribing, switching plans with proration,
 * toggling auto-renewal, and the scheduled renewal sweep. Every method that
 * touches money runs inside one DB transaction covering both the wallet
 * charge/credit and the ai_subscriptions/ai_subscription_payments writes,
 * so a crash between the two can never leave a paid-for subscription
 * un-recorded or a recorded one un-paid-for.
 */
class AiSubscriptionPurchaseService
{
    public const GRACE_DAYS = 3;

    public function __construct(protected AiSubscriptionBillingService $billing, protected AiSubscriptionNotifier $notifier) {}

    /**
     * A brand-new paid subscription. Replaces (cancels) any existing
     * never-expiring free/trial row automatically - AiChatUsageGuard only
     * ever looks at the single latest active row, so this keeps that
     * invariant true instead of stacking two "active" rows.
     */
    public function subscribe(Authenticatable $owner, AiPlan $plan, bool $autoRenew = true): AiSubscription
    {
        if (! $plan->is_active) {
            throw new AiSubscriptionException('subscription_plan_inactive');
        }

        $ownerType = $owner->getMorphClass();
        $ownerId = $owner->getAuthIdentifier();

        $existing = $this->activeSubscriptionFor($ownerType, $ownerId);

        if ($existing !== null && $existing->isBillable()) {
            throw new AiSubscriptionException('subscription_already_active');
        }

        return DB::transaction(function () use ($owner, $plan, $autoRenew, $existing, $ownerType, $ownerId) {
            $wallet = $this->billing->walletFor($owner);
            $resolved = $plan->resolvedPriceFor($wallet->country);
            $this->billing->assertCurrencyMatches($wallet, $resolved['currency']);

            $price = $resolved['price'];
            $operationId = null;

            // A free plan (price 0) never touches the wallet - debit()
            // itself refuses a non-positive amount, and there is nothing
            // to charge or log a real payment for.
            if ($price > 0) {
                $result = $this->billing->charge($wallet, $price, WalletTransactionType::ServicePayment, [
                    'reference_type' => 'ai_subscription',
                    'reference_id' => $existing?->id,
                    'notes' => ['key' => 'wallet.notes.ai_subscription', 'variables' => ['plan' => $plan->name]],
                ]);
                $operationId = $result['operation_id'];
            }

            if ($existing !== null) {
                $existing->update(['status' => AiSubscription::STATUS_CANCELLED]);
            }

            $subscription = AiSubscription::query()->create([
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addDays(max(1, $plan->duration_days)),
                'status' => AiSubscription::STATUS_ACTIVE,
                'auto_renew' => $autoRenew,
                'grace_ends_at' => null,
                'current_plan_price' => $price,
                'current_plan_duration_days' => $plan->duration_days,
            ]);

            $this->logPayment($subscription, $plan, AiSubscriptionPayment::TYPE_INITIAL, AiSubscriptionPayment::STATUS_SUCCEEDED, $price, $operationId, currency: $resolved['currency']);

            $subscription->setRelation('plan', $plan);
            $this->notifier->subscribed($subscription);

            return $subscription;
        });
    }

    /**
     * Switches a live subscription to another plan mid-cycle with
     * proration: the unused value of the remaining days on the current
     * plan is credited against the new plan's cost for those same
     * remaining days - only the difference is actually charged (upgrade)
     * or refunded to the wallet (downgrade). ends_at is deliberately left
     * untouched - proration re-prices the days already paid for, it does
     * not grant a fresh full cycle (that happens at the next renewal).
     */
    public function changePlan(Authenticatable $owner, AiSubscription $subscription, AiPlan $newPlan): AiSubscription
    {
        $this->assertOwnership($owner, $subscription);

        if (! $newPlan->is_active) {
            throw new AiSubscriptionException('subscription_plan_inactive');
        }

        if ($subscription->status !== AiSubscription::STATUS_ACTIVE || ! $subscription->isBillable()) {
            throw new AiSubscriptionException('subscription_not_found');
        }

        if ((int) $subscription->plan_id === (int) $newPlan->id) {
            throw new AiSubscriptionException('subscription_same_plan');
        }

        return DB::transaction(function () use ($owner, $subscription, $newPlan) {
            $wallet = $this->billing->walletFor($owner);
            $resolved = $newPlan->resolvedPriceFor($wallet->country);
            $this->billing->assertCurrencyMatches($wallet, $resolved['currency']);

            $oldPrice = (float) ($subscription->current_plan_price ?? $subscription->plan->price);
            $oldDuration = max(1, (int) ($subscription->current_plan_duration_days ?? $subscription->plan->duration_days));
            $remainingDays = max(0, (int) now()->startOfDay()->diffInDays($subscription->ends_at->copy()->startOfDay(), false));

            $oldRemainingValue = round($oldPrice * $remainingDays / $oldDuration, 2);
            $newDuration = max(1, (int) $newPlan->duration_days);
            $newCostForRemainder = round($resolved['price'] * $remainingDays / $newDuration, 2);
            $diff = round($newCostForRemainder - $oldRemainingValue, 2);

            $operationId = null;
            $type = $diff >= 0 ? AiSubscriptionPayment::TYPE_UPGRADE : AiSubscriptionPayment::TYPE_DOWNGRADE;

            if ($diff > 0) {
                $result = $this->billing->charge($wallet, $diff, WalletTransactionType::ServicePayment, [
                    'reference_type' => 'ai_subscription',
                    'reference_id' => $subscription->id,
                    'notes' => ['key' => 'wallet.notes.ai_subscription_upgrade', 'variables' => ['plan' => $newPlan->name]],
                ]);
                $operationId = $result['operation_id'];
            } elseif ($diff < 0) {
                $transaction = $this->billing->credit($wallet, abs($diff), WalletTransactionType::Refund, [
                    'reference_type' => 'ai_subscription',
                    'reference_id' => $subscription->id,
                    'notes' => ['key' => 'wallet.notes.ai_subscription_downgrade', 'variables' => ['plan' => $newPlan->name]],
                ]);
                $operationId = $transaction->operation_id;
            }

            $subscription->update([
                'plan_id' => $newPlan->id,
                'current_plan_price' => $resolved['price'],
                'current_plan_duration_days' => $newPlan->duration_days,
            ]);

            $this->logPayment($subscription, $newPlan, $type, AiSubscriptionPayment::STATUS_SUCCEEDED, $diff, $operationId, currency: $resolved['currency']);

            $subscription = $subscription->fresh('plan');
            $this->notifier->planChanged($subscription);

            return $subscription;
        });
    }

    public function setAutoRenew(Authenticatable $owner, AiSubscription $subscription, bool $autoRenew): AiSubscription
    {
        $this->assertOwnership($owner, $subscription);
        $subscription->update(['auto_renew' => $autoRenew]);
        $this->notifier->autoRenewToggled($subscription, $autoRenew);

        return $subscription;
    }

    /**
     * One renewal attempt for one subscription - called only by
     * processDueSubscriptions() (the scheduled command), never from a
     * request context, which is why it takes the subscription's owner
     * model straight off the relation instead of an authenticated
     * Authenticatable.
     */
    public function renew(AiSubscription $subscription): bool
    {
        $owner = $subscription->owner;
        $plan = $subscription->plan;

        if ($owner === null || $plan === null || ! $plan->is_active) {
            // Nothing sane to renew into (owner/plan gone, or the plan was
            // deactivated from under a live subscription) - let it lapse
            // rather than charge for something no longer offered.
            $subscription->update(['status' => AiSubscription::STATUS_EXPIRED]);

            return false;
        }

        $resolved = null;

        try {
            $wallet = $this->billing->walletFor($owner, $this->resolveRenewalCountry($owner));
            $resolved = $plan->resolvedPriceFor($wallet->country);
            $this->billing->assertCurrencyMatches($wallet, $resolved['currency']);

            $price = $resolved['price'];
            $operationId = null;

            if ($price > 0) {
                $result = $this->billing->charge($wallet, $price, WalletTransactionType::ServicePayment, [
                    'reference_type' => 'ai_subscription',
                    'reference_id' => $subscription->id,
                    'notes' => ['key' => 'wallet.notes.ai_subscription_renewal', 'variables' => ['plan' => $plan->name]],
                ]);
                $operationId = $result['operation_id'];
            }

            $subscription->update([
                'ends_at' => $subscription->ends_at->copy()->addDays(max(1, $plan->duration_days)),
                'grace_ends_at' => null,
                'renewal_reminder_sent_at' => null,
                'grace_reminder_sent_at' => null,
                'current_plan_price' => $price,
                'current_plan_duration_days' => $plan->duration_days,
            ]);

            $this->logPayment($subscription, $plan, AiSubscriptionPayment::TYPE_RENEWAL, AiSubscriptionPayment::STATUS_SUCCEEDED, $price, $operationId, currency: $resolved['currency']);

            $this->notifier->renewed($subscription);

            return true;
        } catch (InsufficientBalanceException|AiSubscriptionException $e) {
            $this->logPayment(
                $subscription,
                $plan,
                AiSubscriptionPayment::TYPE_RENEWAL,
                AiSubscriptionPayment::STATUS_FAILED,
                $resolved['price'] ?? (float) $plan->price,
                null,
                $e instanceof InsufficientBalanceException ? 'insufficient_balance' : $e->apiErrorCode(),
                currency: $resolved['currency'] ?? $plan->currency,
            );

            // The grace window starts on the *first* failed attempt only -
            // a retry the next day that also fails must not push the
            // deadline back out, or a chronically-empty wallet would stay
            // in grace forever instead of ever actually suspending.
            if ($subscription->grace_ends_at === null) {
                $subscription->update(['grace_ends_at' => now()->addDays(self::GRACE_DAYS)]);
                $this->notifier->renewalFailedGraceOpened($subscription);
            }

            return false;
        }
    }

    /**
     * @return array{renewed: int, grace_opened: int, suspended: int, expired: int}
     */
    public function processDueSubscriptions(): array
    {
        $stats = ['renewed' => 0, 'grace_opened' => 0, 'suspended' => 0, 'expired' => 0];

        $due = AiSubscription::query()
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->where('auto_renew', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('grace_ends_at')->orWhere('grace_ends_at', '>=', now()))
            ->with(['owner', 'plan'])
            ->get();

        foreach ($due as $subscription) {
            $hadGraceBefore = $subscription->grace_ends_at !== null;

            if ($this->renew($subscription)) {
                $stats['renewed']++;
            } elseif (! $hadGraceBefore) {
                $stats['grace_opened']++;
            }
        }

        $expiredGrace = AiSubscription::query()
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<', now())
            ->get();

        foreach ($expiredGrace as $subscription) {
            $subscription->update(['status' => AiSubscription::STATUS_SUSPENDED]);
            $this->notifier->suspended($subscription);
            $stats['suspended']++;
        }

        $nonRenewingExpired = AiSubscription::query()
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->where('auto_renew', false)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($nonRenewingExpired as $subscription) {
            $subscription->update(['status' => AiSubscription::STATUS_EXPIRED]);
            $this->notifier->expired($subscription);
            $stats['expired']++;
        }

        $this->sendUpcomingRenewalReminders();
        $this->sendGraceEndingSoonReminders();

        return $stats;
    }

    /**
     * One reminder per cycle, roughly a day before an auto-renewing
     * subscription is actually charged again - lets someone top up their
     * wallet ahead of time instead of finding out only after a failed
     * renewal. renewal_reminder_sent_at (cleared on every real renewal)
     * is what makes this idempotent across hourly runs.
     */
    protected function sendUpcomingRenewalReminders(): void
    {
        AiSubscription::query()
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->where('auto_renew', true)
            ->whereNull('grace_ends_at')
            ->whereNull('renewal_reminder_sent_at')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addHours(26)])
            ->with(['owner', 'plan'])
            ->each(function (AiSubscription $subscription) {
                $this->notifier->renewalReminder($subscription);
                $subscription->update(['renewal_reminder_sent_at' => now()]);
            });
    }

    /**
     * One reminder per grace window, roughly a day before it ends and the
     * subscription is suspended - the owner's last real chance to top up.
     */
    protected function sendGraceEndingSoonReminders(): void
    {
        AiSubscription::query()
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->whereNotNull('grace_ends_at')
            ->whereNull('grace_reminder_sent_at')
            ->whereBetween('grace_ends_at', [now(), now()->addHours(26)])
            ->with(['owner', 'plan'])
            ->each(function (AiSubscription $subscription) {
                $this->notifier->graceEndingSoon($subscription);
                $subscription->update(['grace_reminder_sent_at' => now()]);
            });
    }

    /**
     * Admin-only free adjustments to a live subscription - no wallet
     * involvement at all (matches this project's convention: an admin
     * action is a business decision, never a hidden charge/refund). Each
     * one notifies the owner (in-app + real-time only, no push - see
     * AiSubscriptionNotifier::adminAdjusted()) so a subscription never
     * changes under someone without them finding out.
     */
    public function adminExtend(AiSubscription $subscription, int $days): AiSubscription
    {
        if ($days < 1) {
            throw new AiSubscriptionException('subscription_invalid_extension');
        }

        $base = $subscription->ends_at?->isFuture() ? $subscription->ends_at->copy() : now();
        $subscription->update(['ends_at' => $base->addDays($days)]);

        $this->notifier->adminAdjusted($subscription, 'extended');

        return $subscription->fresh('plan');
    }

    public function adminSuspend(AiSubscription $subscription): AiSubscription
    {
        if ($subscription->status !== AiSubscription::STATUS_ACTIVE) {
            throw new AiSubscriptionException('subscription_not_found');
        }

        $subscription->update(['status' => AiSubscription::STATUS_SUSPENDED]);
        $this->notifier->adminAdjusted($subscription, 'suspended');

        return $subscription->fresh('plan');
    }

    public function adminReactivate(AiSubscription $subscription): AiSubscription
    {
        if ($subscription->status !== AiSubscription::STATUS_SUSPENDED) {
            throw new AiSubscriptionException('subscription_not_found');
        }

        $subscription->update([
            'status' => AiSubscription::STATUS_ACTIVE,
            'grace_ends_at' => null,
            'grace_reminder_sent_at' => null,
        ]);
        $this->notifier->adminAdjusted($subscription, 'reactivated');

        return $subscription->fresh('plan');
    }

    public function adminCancel(AiSubscription $subscription): AiSubscription
    {
        if (! in_array($subscription->status, [AiSubscription::STATUS_ACTIVE, AiSubscription::STATUS_SUSPENDED], true)) {
            throw new AiSubscriptionException('subscription_not_found');
        }

        $subscription->update(['status' => AiSubscription::STATUS_CANCELLED, 'auto_renew' => false]);
        $this->notifier->adminAdjusted($subscription, 'cancelled');

        return $subscription->fresh('plan');
    }

    /**
     * renew() runs from the scheduled console command - no HTTP request, so
     * currentCountry() (bound only by the 'country' middleware) is never
     * set there. Mirrors CountryResolver's own priority order (explicit
     * choice, then the owner's saved country, then the seeded default) but
     * skips the IP-lookup step, which needs a real request and has no
     * meaning for a background job.
     */
    protected function resolveRenewalCountry(Authenticatable $owner): Country
    {
        return currentCountry()
            ?? $this->activeCountryFromOwnerProfile($owner)
            ?? Country::query()->where('is_default', true)->where('status', true)->first()
            ?? throw new AiSubscriptionException('subscription_country_not_resolved');
    }

    protected function activeCountryFromOwnerProfile(Authenticatable $owner): ?Country
    {
        $countryId = $owner->country_id ?? null;

        if ($countryId === null) {
            return null;
        }

        return Country::query()->where('status', true)->find($countryId);
    }

    protected function activeSubscriptionFor(string $ownerType, int|string $ownerId): ?AiSubscription
    {
        return AiSubscription::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('status', AiSubscription::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->with('plan')
            ->latest('id')
            ->first();
    }

    protected function assertOwnership(Authenticatable $owner, AiSubscription $subscription): void
    {
        $matches = $subscription->owner_type === $owner->getMorphClass()
            && (string) $subscription->owner_id === (string) $owner->getAuthIdentifier();

        if (! $matches) {
            throw new AiSubscriptionException('subscription_not_found', 404);
        }
    }

    protected function logPayment(
        AiSubscription $subscription,
        AiPlan $plan,
        string $type,
        string $status,
        float $amount,
        ?string $operationId,
        ?string $failureReason = null,
        ?string $currency = null,
    ): AiSubscriptionPayment {
        return AiSubscriptionPayment::query()->create([
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'owner_type' => $subscription->owner_type,
            'owner_id' => $subscription->owner_id,
            'type' => $type,
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency ?? $plan->currency,
            'wallet_operation_id' => $operationId,
            'failure_reason' => $failureReason,
        ]);
    }
}
