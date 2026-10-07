<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Exceptions\AiSubscriptionException;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Models\AiSubscriptionPayment;
use Modules\AI\Services\AiSubscriptionNotifier;
use Modules\AI\Services\AiSubscriptionPurchaseService;
use Modules\AI\Services\AiSubscriptionReportService;
use Modules\User\Models\User;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Covers the 2026-09-30 "make the subscription system serve the business
 * better" pass: plan marketing fields (badge/features/discount), the
 * AiSubscriptionNotifier lifecycle notifications, the renewal/grace-ending
 * reminder sweep, the admin reporting numbers, and the admin's free
 * (non-wallet) extend/suspend/reactivate/cancel actions.
 *
 * Notifications go through NotificationCenter::send(), which defers the
 * actual send with DB::afterCommit() - a callback that never fires inside
 * a RefreshDatabase test's wrapping transaction. So instead of asserting
 * database notification rows (unreliable here), these tests bind a Mockery
 * spy over AiSubscriptionNotifier and assert exactly which lifecycle
 * method fired - a direct, reliable check of the actual trigger logic.
 */
class AiSubscriptionImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'eg']);

        $this->country = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true,
        ]);

        app()->instance('resolved_country', $this->country);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'AI Improvements Test User',
            'email' => 'ai-improve-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    private function makePlan(array $overrides = []): AiPlan
    {
        // See AiPlanCountryPricingTest::makePlan() for why this translates
        // a plain 'currency' => 'CODE' override into currency_id.
        if (array_key_exists('currency', $overrides)) {
            $code = $overrides['currency'];
            unset($overrides['currency']);
            $overrides['currency_id'] = Currency::query()->firstOrCreate(
                ['code' => $code],
                ['symbol' => $code, 'decimal_places' => 2],
            )->id;
        }

        return AiPlan::query()->create(array_merge([
            'name' => 'Pro Plan',
            'code' => 'pro-'.uniqid(),
            'usage_minutes' => 600,
            'cooldown_minutes' => 0,
            'duration_days' => 30,
            'price' => 100,
            'currency_id' => $this->country->currency_id,
            'is_trial' => false,
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    private function fundedWallet(User $owner, int $spendOnlyMinor = 1000000): Wallet
    {
        $wallet = app(WalletService::class)->firstOrCreateWallet($owner, $this->country);
        $wallet->update(['spend_only_minor' => $spendOnlyMinor]);

        return $wallet->fresh();
    }

    private function purchaseService(): AiSubscriptionPurchaseService
    {
        return app(AiSubscriptionPurchaseService::class);
    }

    // ------------------------------------------------------------- plan marketing fields

    public function test_plan_discount_percent_is_computed_from_original_price(): void
    {
        $plan = $this->makePlan(['price' => 80, 'original_price' => 100]);
        $this->assertSame(20, $plan->discountPercent());

        $noDiscount = $this->makePlan(['price' => 80, 'original_price' => null]);
        $this->assertNull($noDiscount->discountPercent());

        $notActuallyCheaper = $this->makePlan(['price' => 100, 'original_price' => 80]);
        $this->assertNull($notActuallyCheaper->discountPercent());
    }

    public function test_plan_stores_badge_featured_flag_and_features_list(): void
    {
        $plan = $this->makePlan([
            'badge' => 'الأكثر شيوعاً',
            'is_featured' => true,
            'features' => ['دعم أولوية', 'بدون حدود يومية'],
        ]);

        $plan->refresh();
        $this->assertSame('الأكثر شيوعاً', $plan->badge);
        $this->assertTrue($plan->is_featured);
        $this->assertSame(['دعم أولوية', 'بدون حدود يومية'], $plan->features);
    }

    // ------------------------------------------------------------- notifier lifecycle wiring

    public function test_subscribing_notifies_the_owner(): void
    {
        $spy = $this->spy(AiSubscriptionNotifier::class);

        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 50]);
        $this->fundedWallet($owner);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $spy->shouldHaveReceived('subscribed')->once()->withArgs(fn (AiSubscription $s) => $s->id === $subscription->id);
    }

    public function test_changing_plan_notifies_the_owner(): void
    {
        $owner = $this->makeUser();
        $oldPlan = $this->makePlan(['price' => 30]);
        $this->fundedWallet($owner);
        $subscription = $this->purchaseService()->subscribe($owner, $oldPlan);

        $spy = $this->spy(AiSubscriptionNotifier::class);
        $newPlan = $this->makePlan(['price' => 60]);
        $this->purchaseService()->changePlan($owner, $subscription->fresh(), $newPlan);

        $spy->shouldHaveReceived('planChanged')->once();
    }

    public function test_toggling_auto_renew_notifies_the_owner_with_the_new_state(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 0]);
        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $spy = $this->spy(AiSubscriptionNotifier::class);
        $this->purchaseService()->setAutoRenew($owner, $subscription, false);

        $spy->shouldHaveReceived('autoRenewToggled')->once()->withArgs(fn (AiSubscription $s, bool $enabled) => $enabled === false);
    }

    public function test_successful_renewal_notifies_renewed(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 40]);
        $this->fundedWallet($owner);
        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $spy = $this->spy(AiSubscriptionNotifier::class);
        $this->purchaseService()->renew($subscription->fresh());

        $spy->shouldHaveReceived('renewed')->once();
        $spy->shouldNotHaveReceived('renewalFailedGraceOpened');
    }

    public function test_failed_renewal_notifies_grace_opened_only_once_across_retries(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 100]);
        $this->fundedWallet($owner, spendOnlyMinor: 10000); // exactly enough for the initial charge, nothing left
        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $spy = $this->spy(AiSubscriptionNotifier::class);

        $this->purchaseService()->renew($subscription->fresh());
        $this->purchaseService()->renew($subscription->fresh());

        $spy->shouldHaveReceived('renewalFailedGraceOpened')->once();
    }

    public function test_process_due_subscriptions_notifies_suspended_and_expired(): void
    {
        $owner1 = $this->makeUser();
        $plan = $this->makePlan(['price' => 100]);
        $this->fundedWallet($owner1, spendOnlyMinor: 10000);
        $suspendCandidate = $this->purchaseService()->subscribe($owner1, $plan);
        $suspendCandidate->update(['grace_ends_at' => now()->subMinute()]);

        $owner2 = $this->makeUser();
        $this->fundedWallet($owner2, spendOnlyMinor: 100000);
        $expireCandidate = $this->purchaseService()->subscribe($owner2, $this->makePlan(['price' => 50]), autoRenew: false);
        $expireCandidate->update(['ends_at' => now()->subMinute()]);

        $spy = $this->spy(AiSubscriptionNotifier::class);
        $this->purchaseService()->processDueSubscriptions();

        $spy->shouldHaveReceived('suspended')->once();
        $spy->shouldHaveReceived('expired')->once();
    }

    // ------------------------------------------------------------- renewal / grace reminders

    public function test_upcoming_renewal_reminder_fires_once_and_is_not_repeated(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 50]);
        $this->fundedWallet($owner);
        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $subscription->update(['ends_at' => now()->addHours(10)]); // inside the 26h reminder window, not yet due

        $spy = $this->spy(AiSubscriptionNotifier::class);

        $this->purchaseService()->processDueSubscriptions();
        $this->assertNotNull($subscription->fresh()->renewal_reminder_sent_at);
        $spy->shouldHaveReceived('renewalReminder')->once();

        // A second sweep must not send it again.
        $this->purchaseService()->processDueSubscriptions();
        $spy->shouldHaveReceived('renewalReminder')->once();
    }

    public function test_grace_ending_soon_reminder_fires_once_and_is_not_repeated(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 100]);
        $this->fundedWallet($owner, spendOnlyMinor: 10000);
        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $subscription->update(['grace_ends_at' => now()->addHours(10)]);

        $spy = $this->spy(AiSubscriptionNotifier::class);

        $this->purchaseService()->processDueSubscriptions();
        $this->assertNotNull($subscription->fresh()->grace_reminder_sent_at);
        $spy->shouldHaveReceived('graceEndingSoon')->once();

        $this->purchaseService()->processDueSubscriptions();
        $spy->shouldHaveReceived('graceEndingSoon')->once();
    }

    // ------------------------------------------------------------- admin actions

    public function test_admin_extend_pushes_ends_at_forward_and_notifies(): void
    {
        $owner = $this->makeUser();
        $plan = $this->makePlan(['price' => 0]);
        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $originalEndsAt = $subscription->ends_at->copy();

        $spy = $this->spy(AiSubscriptionNotifier::class);
        $updated = $this->purchaseService()->adminExtend($subscription, 15);

        $this->assertTrue($updated->ends_at->equalTo($originalEndsAt->copy()->addDays(15)));
        $spy->shouldHaveReceived('adminAdjusted')->once()->withArgs(fn ($s, $action) => $action === 'extended');
    }

    public function test_admin_extend_rejects_a_non_positive_day_count(): void
    {
        $owner = $this->makeUser();
        $subscription = $this->purchaseService()->subscribe($owner, $this->makePlan(['price' => 0]));

        $this->expectException(AiSubscriptionException::class);
        $this->purchaseService()->adminExtend($subscription, 0);
    }

    public function test_admin_suspend_then_reactivate_round_trips_and_clears_grace(): void
    {
        $owner = $this->makeUser();
        $subscription = $this->purchaseService()->subscribe($owner, $this->makePlan(['price' => 0]));
        $subscription->update(['grace_ends_at' => now()->addDay()]);

        $suspended = $this->purchaseService()->adminSuspend($subscription);
        $this->assertSame(AiSubscription::STATUS_SUSPENDED, $suspended->status);

        $reactivated = $this->purchaseService()->adminReactivate($suspended);
        $this->assertSame(AiSubscription::STATUS_ACTIVE, $reactivated->status);
        $this->assertNull($reactivated->grace_ends_at);
    }

    public function test_admin_cancel_stops_auto_renew(): void
    {
        $owner = $this->makeUser();
        $subscription = $this->purchaseService()->subscribe($owner, $this->makePlan(['price' => 0]));

        $cancelled = $this->purchaseService()->adminCancel($subscription);

        $this->assertSame(AiSubscription::STATUS_CANCELLED, $cancelled->status);
        $this->assertFalse($cancelled->auto_renew);
    }

    public function test_admin_suspend_on_an_already_suspended_subscription_is_rejected(): void
    {
        $owner = $this->makeUser();
        $subscription = $this->purchaseService()->subscribe($owner, $this->makePlan(['price' => 0]));
        $this->purchaseService()->adminSuspend($subscription);

        $this->expectException(AiSubscriptionException::class);
        $this->purchaseService()->adminSuspend($subscription->fresh());
    }

    // ------------------------------------------------------------- admin reporting

    public function test_overview_counts_and_sums_are_correct(): void
    {
        $planA = $this->makePlan(['price' => 100, 'duration_days' => 30]);
        $planB = $this->makePlan(['price' => 200, 'duration_days' => 30]);
        $trialPlan = $this->makePlan(['price' => 0, 'is_trial' => true]);

        // Paid, active, auto-renewing -> counts toward paid_active and MRR.
        $subWithPayment = AiSubscription::query()->create([
            'owner_type' => 'user', 'owner_id' => 1, 'plan_id' => $planA->id,
            'starts_at' => now(), 'ends_at' => now()->addDays(20), 'status' => AiSubscription::STATUS_ACTIVE,
            'auto_renew' => true, 'current_plan_price' => 100, 'current_plan_duration_days' => 30,
        ]);

        // Paid, active, NOT auto-renewing -> paid_active only, excluded from MRR.
        AiSubscription::query()->create([
            'owner_type' => 'user', 'owner_id' => 2, 'plan_id' => $planB->id,
            'starts_at' => now(), 'ends_at' => now()->addDays(10), 'status' => AiSubscription::STATUS_ACTIVE,
            'auto_renew' => false, 'current_plan_price' => 200, 'current_plan_duration_days' => 30,
        ]);

        // Trial (free, no ends_at) -> trial_subscribers only.
        AiSubscription::query()->create([
            'owner_type' => 'user', 'owner_id' => 3, 'plan_id' => $trialPlan->id,
            'starts_at' => now(), 'ends_at' => null, 'status' => AiSubscription::STATUS_ACTIVE,
            'auto_renew' => false, 'current_plan_price' => 0,
        ]);

        // In grace period -> counts in both paid_active and in_grace_period.
        AiSubscription::query()->create([
            'owner_type' => 'user', 'owner_id' => 4, 'plan_id' => $planA->id,
            'starts_at' => now(), 'ends_at' => now()->addDays(5), 'status' => AiSubscription::STATUS_ACTIVE,
            'auto_renew' => true, 'grace_ends_at' => now()->addDay(),
            'current_plan_price' => 100, 'current_plan_duration_days' => 30,
        ]);

        // Suspended.
        AiSubscription::query()->create([
            'owner_type' => 'user', 'owner_id' => 5, 'plan_id' => $planA->id,
            'starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(3), 'status' => AiSubscription::STATUS_SUSPENDED,
            'auto_renew' => true, 'current_plan_price' => 100, 'current_plan_duration_days' => 30,
        ]);

        // Cancelled recently -> churn.
        AiSubscription::query()->create([
            'owner_type' => 'user', 'owner_id' => 6, 'plan_id' => $planA->id,
            'starts_at' => now()->subDays(10), 'ends_at' => now()->subDays(1), 'status' => AiSubscription::STATUS_CANCELLED,
            'auto_renew' => false, 'current_plan_price' => 100, 'current_plan_duration_days' => 30,
        ]);

        AiSubscriptionPayment::query()->create([
            'subscription_id' => $subWithPayment->id, 'plan_id' => $planA->id, 'owner_type' => 'user', 'owner_id' => 1,
            'type' => AiSubscriptionPayment::TYPE_INITIAL, 'status' => AiSubscriptionPayment::STATUS_SUCCEEDED,
            'amount' => 100, 'currency' => 'EGP',
        ]);

        $overview = app(AiSubscriptionReportService::class)->overview();

        $this->assertSame(1, $overview['trial_subscribers']);
        $this->assertSame(3, $overview['paid_active']); // planA active, planB active, planA-in-grace
        $this->assertSame(1, $overview['in_grace_period']);
        $this->assertSame(1, $overview['suspended']);
        $this->assertSame(1, $overview['cancelled_or_expired_last_30_days']);
        $this->assertEquals(200.0, $overview['mrr']); // two auto_renew=true active-billable subs at 100 each (planA + planA-in-grace)
        $this->assertEquals(100.0, $overview['revenue_this_month']);
        $this->assertCount(3, $overview['plan_distribution']);
    }
}
