<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\AI\Exceptions\AiSubscriptionException;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Models\AiSubscriptionPayment;
use Modules\AI\Services\AiSubscriptionBillingService;
use Modules\AI\Services\AiSubscriptionPurchaseService;
use Modules\User\Models\User;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Full coverage for the wallet-linked AI subscription system built
 * 2026-09-29: AiSubscriptionBillingService (the only door to the wallet)
 * and AiSubscriptionPurchaseService (subscribe / prorated plan switch /
 * auto-renew toggle / the scheduled renewal sweep with its 3-day grace
 * period, per the product decisions the user gave for this feature).
 *
 * No php/composer CLI is available in the environment that wrote this
 * file - every line was verified by hand against the real service code
 * (AiSubscriptionBillingService, AiSubscriptionPurchaseService,
 * WalletService, the ai_subscriptions/ai_plans/ai_subscription_payments
 * migrations) rather than executed. Run with:
 *   php artisan test Modules/AI --filter=AiSubscriptionBillingTest
 */
class AiSubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'eg']);

        $this->country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $this->currency->id, 'status' => true,
        ]);

        // AiSubscriptionBillingService::walletFor() reads currentCountry(),
        // which only resolves once the 'country' middleware has bound
        // 'resolved_country' - these are Feature tests calling the
        // services directly (no HTTP request), so bind it by hand exactly
        // the way that middleware would.
        app()->instance('resolved_country', $this->country);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'AI Subscription Test User',
            'email' => 'ai-sub-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    private function makePlan(array $overrides = []): AiPlan
    {
        return AiPlan::query()->create(array_merge([
            'name' => 'Pro Plan',
            'code' => 'pro-'.uniqid(),
            'usage_minutes' => 600,
            'cooldown_minutes' => 0,
            'duration_days' => 30,
            'price' => 100,
            'currency' => 'EGP',
            'is_trial' => false,
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    /**
     * Creates the owner's wallet up front (so subscribe()'s own
     * firstOrCreateWallet() call finds it already funded) and seeds its
     * two buckets directly - bypassing WalletService::credit() on purpose,
     * so a test can set up an exact starting balance without that credit
     * itself being part of what's under test.
     */
    private function fundedWallet(User $owner, int $spendOnlyMinor = 0, int $withdrawableMinor = 0): Wallet
    {
        $wallet = app(WalletService::class)->firstOrCreateWallet($owner, $this->country);
        $wallet->update([
            'spend_only_minor' => $spendOnlyMinor,
            'withdrawable_minor' => $withdrawableMinor,
        ]);

        return $wallet->fresh();
    }

    private function purchaseService(): AiSubscriptionPurchaseService
    {
        return app(AiSubscriptionPurchaseService::class);
    }

    // ------------------------------------------------------------- subscribe()

    public function test_subscribe_charges_the_wallet_and_creates_an_active_subscription(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 150]);
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 20000); // 200.00 EGP

        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $this->assertSame(AiSubscription::STATUS_ACTIVE, $subscription->status);
        $this->assertTrue($subscription->auto_renew);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertEquals(150.0, (float) $subscription->current_plan_price);
        $this->assertSame(30, $subscription->current_plan_duration_days);
        $this->assertTrue($subscription->ends_at->isFuture());

        $this->assertSame(5000, $wallet->fresh()->spend_only_minor); // 200 - 150 = 50.00
        $this->assertSame(0, $wallet->fresh()->withdrawable_minor);

        $payment = AiSubscriptionPayment::query()->where('subscription_id', $subscription->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(AiSubscriptionPayment::TYPE_INITIAL, $payment->type);
        $this->assertSame(AiSubscriptionPayment::STATUS_SUCCEEDED, $payment->status);
        $this->assertEquals(150.0, (float) $payment->amount);
        $this->assertNotNull($payment->wallet_operation_id);
    }

    public function test_subscribe_honors_auto_renew_false(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 50]);
        $this->fundedWallet($owner, spendOnlyMinor: 10000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan, autoRenew: false);

        $this->assertFalse($subscription->auto_renew);
    }

    public function test_subscribe_splits_the_charge_spend_only_first_then_withdrawable_under_one_operation_id(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 100]);
        // Only 30.00 in spend_only, the remaining 70.00 must come from
        // withdrawable - matching docs/wallet-structure.md's own proposed
        // split-debit convention (spend_only exhausted first).
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 3000, withdrawableMinor: 20000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $wallet->refresh();
        $this->assertSame(0, $wallet->spend_only_minor);
        $this->assertSame(13000, $wallet->withdrawable_minor); // 200 - 70 = 130.00

        $payment = AiSubscriptionPayment::query()->where('subscription_id', $subscription->id)->first();
        $operationId = $payment->wallet_operation_id;
        $this->assertNotNull($operationId);

        $rows = $wallet->transactions()->where('operation_id', $operationId)->get();
        $this->assertCount(2, $rows, 'a split charge must produce exactly two wallet_transactions rows sharing one operation_id');
        $this->assertEqualsCanonicalizing(['spend_only', 'withdrawable'], $rows->pluck('bucket')->map(fn ($b) => $b instanceof \Modules\Wallet\Enums\WalletBucket ? $b->value : (string) $b)->all());
    }

    public function test_subscribe_fails_when_combined_balance_is_insufficient_and_charges_nothing(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 100]);
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 2000, withdrawableMinor: 3000); // 50.00 total

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->purchaseService()->subscribe($owner, $plan);
        } finally {
            // Never a partial charge: both buckets must be untouched, and
            // no subscription/payment row left behind by the failed attempt.
            $wallet->refresh();
            $this->assertSame(2000, $wallet->spend_only_minor);
            $this->assertSame(3000, $wallet->withdrawable_minor);
            $this->assertSame(0, AiSubscription::query()->count());
            $this->assertSame(0, AiSubscriptionPayment::query()->count());
        }
    }

    public function test_subscribe_rejects_a_plan_priced_in_a_different_currency_than_the_wallet(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 50, 'currency' => 'USD']);
        $this->fundedWallet($owner, spendOnlyMinor: 100000); // wallet currency is EGP

        $this->expectException(AiSubscriptionException::class);
        $this->purchaseService()->subscribe($owner, $plan);
    }

    public function test_subscribe_rejects_an_inactive_plan(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 50, 'is_active' => false]);
        $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $this->expectException(AiSubscriptionException::class);
        $this->purchaseService()->subscribe($owner, $plan);
    }

    public function test_a_free_plan_never_touches_the_wallet(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 0]);
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 500);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $this->assertSame(500, $wallet->fresh()->spend_only_minor);
        $payment = AiSubscriptionPayment::query()->where('subscription_id', $subscription->id)->first();
        $this->assertNull($payment->wallet_operation_id);
    }

    public function test_subscribing_again_while_already_billable_is_rejected(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 50]);
        $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $this->purchaseService()->subscribe($owner, $plan);

        $this->expectException(AiSubscriptionException::class);
        $this->purchaseService()->subscribe($owner, $this->makePlan(['price' => 50]));
    }

    // ------------------------------------------------------------- changePlan()

    public function test_change_plan_upgrade_charges_only_the_prorated_difference(): void
    {
        $owner = $this->makeUser();
        $oldPlan = $this->makePlan(['price' => 30, 'duration_days' => 30]);
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $subscription = $this->purchaseService()->subscribe($owner, $oldPlan);
        $wallet->refresh();
        $spentOnInitial = 100000 - $wallet->spend_only_minor;
        $this->assertSame(3000, $spentOnInitial); // sanity: 30.00 charged

        // Freeze exactly 10 days remaining out of the 30-day cycle so the
        // proration math is fully deterministic.
        $subscription->update(['ends_at' => now()->addDays(10)]);

        $newPlan = $this->makePlan(['price' => 90, 'duration_days' => 30]);

        $updated = $this->purchaseService()->changePlan($owner, $subscription->fresh(), $newPlan);

        // old remaining value = 30 * 10/30 = 10.00
        // new cost for remainder = 90 * 10/30 = 30.00
        // diff = 20.00 (upgrade, charged)
        $wallet->refresh();
        $this->assertSame(100000 - 3000 - 2000, $wallet->spend_only_minor);
        $this->assertSame((int) $newPlan->id, $updated->plan_id);
        $this->assertEquals(90.0, (float) $updated->current_plan_price);

        $payment = AiSubscriptionPayment::query()->where('subscription_id', $subscription->id)
            ->where('type', AiSubscriptionPayment::TYPE_UPGRADE)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(20.0, (float) $payment->amount);
    }

    public function test_change_plan_downgrade_credits_the_prorated_difference_back_to_the_wallet(): void
    {
        $owner = $this->makeUser();
        $oldPlan = $this->makePlan(['price' => 90, 'duration_days' => 30]);
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $subscription = $this->purchaseService()->subscribe($owner, $oldPlan);
        $subscription->update(['ends_at' => now()->addDays(10)]);
        $wallet->refresh();
        $balanceAfterInitial = $wallet->spend_only_minor;

        $newPlan = $this->makePlan(['price' => 30, 'duration_days' => 30]);

        $updated = $this->purchaseService()->changePlan($owner, $subscription->fresh(), $newPlan);

        // old remaining value = 90 * 10/30 = 30.00, new cost = 30 * 10/30 = 10.00
        // diff = -20.00 (downgrade, credited back)
        $wallet->refresh();
        $this->assertSame($balanceAfterInitial + 2000, $wallet->spend_only_minor);

        $payment = AiSubscriptionPayment::query()->where('subscription_id', $subscription->id)
            ->where('type', AiSubscriptionPayment::TYPE_DOWNGRADE)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(-20.0, (float) $payment->amount);
        $this->assertEquals(30.0, (float) $updated->current_plan_price);
    }

    public function test_change_plan_leaves_ends_at_untouched(): void
    {
        $owner = $this->makeUser();
        $oldPlan = $this->makePlan(['price' => 30]);
        $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $subscription = $this->purchaseService()->subscribe($owner, $oldPlan);
        $originalEndsAt = $subscription->ends_at->copy();

        $newPlan = $this->makePlan(['price' => 60]);
        $updated = $this->purchaseService()->changePlan($owner, $subscription->fresh(), $newPlan);

        $this->assertTrue($originalEndsAt->equalTo($updated->ends_at), 'proration re-prices the current cycle, it must not grant a fresh one');
    }

    public function test_change_plan_to_the_same_plan_is_rejected(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 30]);
        $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $this->expectException(AiSubscriptionException::class);
        $this->purchaseService()->changePlan($owner, $subscription->fresh(), $plan);
    }

    public function test_change_plan_by_a_non_owner_is_rejected(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();
        $plan = $this->makePlan(['price' => 30]);
        $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $newPlan = $this->makePlan(['price' => 60]);

        $this->expectException(AiSubscriptionException::class);
        $this->purchaseService()->changePlan($stranger, $subscription->fresh(), $newPlan);
    }

    // ------------------------------------------------------------- renew() / grace / suspend

    public function test_renew_success_extends_ends_at_and_snapshots_the_live_plan_price(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 40, 'duration_days' => 30]);
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $endsAtBefore = $subscription->ends_at->copy();
        $balanceAfterInitial = $wallet->fresh()->spend_only_minor;

        $ok = $this->purchaseService()->renew($subscription->fresh());

        $this->assertTrue($ok);
        $subscription->refresh();
        $this->assertTrue($subscription->ends_at->equalTo($endsAtBefore->copy()->addDays(30)));
        $this->assertNull($subscription->grace_ends_at);
        $this->assertSame($balanceAfterInitial - 4000, $wallet->fresh()->spend_only_minor);

        $this->assertSame(1, AiSubscriptionPayment::query()->where('subscription_id', $subscription->id)
            ->where('type', AiSubscriptionPayment::TYPE_RENEWAL)->where('status', AiSubscriptionPayment::STATUS_SUCCEEDED)->count());
    }

    public function test_renew_failure_opens_a_grace_window_and_logs_a_failed_payment(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 100, 'duration_days' => 30]);
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 10000); // exactly enough for the initial charge

        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $this->assertSame(0, $wallet->fresh()->spend_only_minor); // drained by the initial charge

        $ok = $this->purchaseService()->renew($subscription->fresh());

        $this->assertFalse($ok);
        $subscription->refresh();
        $this->assertSame(AiSubscription::STATUS_ACTIVE, $subscription->status, 'access continues through the grace period');
        $this->assertNotNull($subscription->grace_ends_at);
        $this->assertTrue($subscription->grace_ends_at->between(now()->addDays(2)->addHours(23), now()->addDays(3)->addMinutes(1)));

        $failedPayment = AiSubscriptionPayment::query()->where('subscription_id', $subscription->id)
            ->where('type', AiSubscriptionPayment::TYPE_RENEWAL)->where('status', AiSubscriptionPayment::STATUS_FAILED)->first();
        $this->assertNotNull($failedPayment);
        $this->assertSame('insufficient_balance', $failedPayment->failure_reason);
    }

    public function test_a_second_failed_renewal_does_not_push_the_grace_deadline_back_out(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 100, 'duration_days' => 30]);
        $this->fundedWallet($owner, spendOnlyMinor: 10000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $this->purchaseService()->renew($subscription->fresh());

        $graceDeadlineAfterFirstFailure = $subscription->fresh()->grace_ends_at;

        Carbon::setTestNow(now()->addDay());
        $this->purchaseService()->renew($subscription->fresh());
        Carbon::setTestNow();

        $this->assertTrue($graceDeadlineAfterFirstFailure->equalTo($subscription->fresh()->grace_ends_at), 'a retry must not extend the original 3-day grace window');
    }

    public function test_process_due_subscriptions_suspends_once_grace_has_expired_with_no_successful_retry(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 100, 'duration_days' => 30]);
        $this->fundedWallet($owner, spendOnlyMinor: 10000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $subscription->update(['grace_ends_at' => now()->subMinute()]);

        $stats = $this->purchaseService()->processDueSubscriptions();

        $this->assertSame(AiSubscription::STATUS_SUSPENDED, $subscription->fresh()->status);
        $this->assertSame(1, $stats['suspended']);
    }

    public function test_process_due_subscriptions_renews_a_due_subscription_that_has_a_funded_wallet(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 50, 'duration_days' => 30]);
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $subscription->update(['ends_at' => now()->subMinute()]);

        $stats = $this->purchaseService()->processDueSubscriptions();

        $this->assertSame(1, $stats['renewed']);
        $this->assertSame(AiSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertTrue($subscription->fresh()->ends_at->isFuture());
    }

    public function test_process_due_subscriptions_opens_grace_for_a_due_subscription_with_an_empty_wallet(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 100, 'duration_days' => 30]);
        $this->fundedWallet($owner, spendOnlyMinor: 10000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $subscription->update(['ends_at' => now()->subMinute()]);

        $stats = $this->purchaseService()->processDueSubscriptions();

        $this->assertSame(1, $stats['grace_opened']);
        $this->assertSame(AiSubscription::STATUS_ACTIVE, $subscription->fresh()->status);
        $this->assertNotNull($subscription->fresh()->grace_ends_at);
    }

    public function test_process_due_subscriptions_expires_a_non_auto_renewing_subscription_cleanly_at_ends_at(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 50, 'duration_days' => 30]);
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan, autoRenew: false);
        $subscription->update(['ends_at' => now()->subMinute()]);
        $balanceBefore = $wallet->fresh()->spend_only_minor;

        $stats = $this->purchaseService()->processDueSubscriptions();

        $this->assertSame(1, $stats['expired']);
        $this->assertSame(AiSubscription::STATUS_EXPIRED, $subscription->fresh()->status);
        $this->assertSame($balanceBefore, $wallet->fresh()->spend_only_minor, 'a non-auto-renewing subscription must never be charged');
    }

    // ------------------------------------------------------------- setAutoRenew()

    public function test_set_auto_renew_toggles_the_flag(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 0]);
        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $this->assertTrue($subscription->auto_renew);

        $updated = $this->purchaseService()->setAutoRenew($owner, $subscription, false);
        $this->assertFalse($updated->fresh()->auto_renew);

        $this->purchaseService()->setAutoRenew($owner, $subscription, true);
        $this->assertTrue($subscription->fresh()->auto_renew);
    }

    public function test_set_auto_renew_by_a_non_owner_is_rejected(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();
        $plan = $this->makePlan(['price' => 0]);
        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $this->expectException(AiSubscriptionException::class);
        $this->purchaseService()->setAutoRenew($stranger, $subscription, false);
    }

    // ------------------------------------------------------------- AiSubscriptionBillingService directly

    public function test_billing_service_rejects_a_wallet_currency_mismatch(): void
    {
        $owner = $this->makeUser();
        $wallet = $this->fundedWallet($owner, spendOnlyMinor: 100000);

        $this->expectException(AiSubscriptionException::class);
        app(AiSubscriptionBillingService::class)->assertCurrencyMatches($wallet, 'USD');
    }

    public function test_billing_service_to_minor_respects_the_currency_decimal_places(): void
    {
        $owner = $this->makeUser();
        $wallet = $this->fundedWallet($owner);

        $this->assertSame(15050, app(AiSubscriptionBillingService::class)->toMinor($wallet, 150.50));
    }
}
