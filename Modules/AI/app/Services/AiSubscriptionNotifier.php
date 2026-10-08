<?php

namespace Modules\AI\Services;

use App\Services\Notifications\NotificationCenter;
use Modules\AI\Models\AiSubscription;

/**
 * Tells the owner what happened to their subscription - one method per
 * lifecycle event, all through NotificationCenter (in-app list + real-time
 * + push, in every language). Kept separate from AiSubscriptionPurchaseService
 * so that service can stay focused on the money/state logic and this one
 * stays focused on wording - matching WalletNotifier's role next to
 * WalletService/TransferService elsewhere in this codebase.
 *
 * Every method is a thin, single-purpose call so tests can assert exactly
 * which lifecycle event fired without depending on NotificationCenter's own
 * after-commit/push delivery internals.
 */
class AiSubscriptionNotifier
{
    public function __construct(private readonly NotificationCenter $center) {}

    public function subscribed(AiSubscription $subscription): void
    {
        $this->center->send(
            $subscription->owner,
            'ai_subscription.subscribed',
            'ai_subscription_subscribed_title',
            'ai_subscription_subscribed_body',
            ['plan' => (string) $subscription->plan?->name],
            ['type' => 'ai_subscription', 'subscription_id' => $subscription->id],
        );
    }

    public function planChanged(AiSubscription $subscription): void
    {
        $this->center->send(
            $subscription->owner,
            'ai_subscription.plan_changed',
            'ai_subscription_plan_changed_title',
            'ai_subscription_plan_changed_body',
            ['plan' => (string) $subscription->plan?->name],
            ['type' => 'ai_subscription', 'subscription_id' => $subscription->id],
        );
    }

    public function renewed(AiSubscription $subscription): void
    {
        $this->center->send(
            $subscription->owner,
            'ai_subscription.renewed',
            'ai_subscription_renewed_title',
            'ai_subscription_renewed_body',
            ['plan' => (string) $subscription->plan?->name],
            ['type' => 'ai_subscription', 'subscription_id' => $subscription->id],
        );
    }

    /** Fired once, only the first time a renewal fails and the grace window opens. */
    public function renewalFailedGraceOpened(AiSubscription $subscription): void
    {
        $this->center->send(
            $subscription->owner,
            'ai_subscription.renewal_failed',
            'ai_subscription_renewal_failed_title',
            'ai_subscription_renewal_failed_body',
            ['plan' => (string) $subscription->plan?->name, 'days' => AiSubscriptionPurchaseService::GRACE_DAYS],
            ['type' => 'ai_subscription', 'subscription_id' => $subscription->id],
        );
    }

    /** One reminder, sent once, roughly a day before the grace period ends. */
    public function graceEndingSoon(AiSubscription $subscription): void
    {
        $this->center->send(
            $subscription->owner,
            'ai_subscription.grace_ending_soon',
            'ai_subscription_grace_ending_soon_title',
            'ai_subscription_grace_ending_soon_body',
            ['plan' => (string) $subscription->plan?->name],
            ['type' => 'ai_subscription', 'subscription_id' => $subscription->id],
        );
    }

    public function suspended(AiSubscription $subscription): void
    {
        $this->center->send(
            $subscription->owner,
            'ai_subscription.suspended',
            'ai_subscription_suspended_title',
            'ai_subscription_suspended_body',
            ['plan' => (string) $subscription->plan?->name],
            ['type' => 'ai_subscription', 'subscription_id' => $subscription->id],
        );
    }

    public function expired(AiSubscription $subscription): void
    {
        $this->center->send(
            $subscription->owner,
            'ai_subscription.expired',
            'ai_subscription_expired_title',
            'ai_subscription_expired_body',
            ['plan' => (string) $subscription->plan?->name],
            ['type' => 'ai_subscription', 'subscription_id' => $subscription->id],
        );
    }

    /** One reminder, sent once, roughly a day before an auto-renewing subscription is charged again. */
    public function renewalReminder(AiSubscription $subscription): void
    {
        $this->center->send(
            $subscription->owner,
            'ai_subscription.renewal_reminder',
            'ai_subscription_renewal_reminder_title',
            'ai_subscription_renewal_reminder_body',
            ['plan' => (string) $subscription->plan?->name, 'price' => (string) $subscription->current_plan_price, 'currency' => (string) $subscription->plan?->currency],
            ['type' => 'ai_subscription', 'subscription_id' => $subscription->id],
        );
    }

    public function autoRenewToggled(AiSubscription $subscription, bool $enabled): void
    {
        $this->center->send(
            $subscription->owner,
            'ai_subscription.auto_renew_'.($enabled ? 'enabled' : 'disabled'),
            $enabled ? 'ai_subscription_auto_renew_enabled_title' : 'ai_subscription_auto_renew_disabled_title',
            $enabled ? 'ai_subscription_auto_renew_enabled_body' : 'ai_subscription_auto_renew_disabled_body',
            [],
            ['type' => 'ai_subscription', 'subscription_id' => $subscription->id],
        );
    }

    /** An admin action, not a billing event - the owner should still know their subscription moved. */
    public function adminAdjusted(AiSubscription $subscription, string $action): void
    {
        $this->center->send(
            $subscription->owner,
            'ai_subscription.admin_adjusted',
            'ai_subscription_admin_adjusted_title',
            'ai_subscription_admin_adjusted_'.$action.'_body',
            ['plan' => (string) $subscription->plan?->name],
            ['type' => 'ai_subscription', 'subscription_id' => $subscription->id],
            push: false,
        );
    }
}
