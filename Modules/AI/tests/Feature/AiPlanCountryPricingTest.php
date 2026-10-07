<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\AI\Models\AiPlan;
use Modules\AI\Models\AiPlanPrice;
use Modules\AI\Models\AiSubscription;
use Modules\AI\Models\AiSubscriptionPayment;
use Modules\AI\Services\AiSubscriptionPurchaseService;
use Modules\Admin\Models\Admin;
use Modules\User\Models\User;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

/**
 * Per-country / per-currency plan pricing (2026-09-30 - "اسعار الباقات تكون
 * على حسب البلد وبرده العمله كل بلد وليها عملتها"). Covers three layers:
 *   - AiPlan::resolvedPriceFor() itself (model-level fallback rule).
 *   - The billing flow (subscribe/changePlan/renew) actually charging the
 *     country-specific price in the wallet's own currency, never the
 *     plan's base price/currency once an override exists.
 *   - The admin CRUD surface (AiPlanPriceController) and the customer-
 *     facing AiUserSubscriptionController::plans() endpoint.
 *
 * No php/composer CLI is available in the environment that wrote this file -
 * every line was verified by hand against the real service/controller code
 * rather than executed. Run with:
 *   php artisan test Modules/AI --filter=AiPlanCountryPricingTest
 */
class AiPlanCountryPricingTest extends TestCase
{
    use RefreshDatabase;

    private Country $egypt;

    private Currency $egp;

    private Country $saudi;

    private Currency $sar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->egp = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        $egFlag = Flag::create(['code' => 'eg']);
        $this->egypt = Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10,
            'is_default' => true, 'flag_id' => $egFlag->id, 'currency_id' => $this->egp->id, 'status' => true,
        ]);

        $this->sar = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س', 'decimal_places' => 2]);
        $saFlag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9,
            'is_default' => false, 'flag_id' => $saFlag->id, 'currency_id' => $this->sar->id, 'status' => true,
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'name' => 'AI Pricing Test User',
            'email' => 'ai-pricing-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    private function actingAsAdmin(): Admin
    {
        $admin = Admin::query()->create([
            'name' => 'Pricing Test Admin',
            'email' => 'pricing-admin-'.uniqid().'@example.test',
            'password' => 'password',
            'status' => true,
        ]);

        Sanctum::actingAs($admin, ['*'], 'admin_api');

        return $admin;
    }

    private function makePlan(array $overrides = []): AiPlan
    {
        // ai_plans.currency became a currency_id relation - every call site
        // in this file still passes a plain 'currency' => 'EGP'/'SAR' code
        // (unchanged, on purpose, so the override list below stays
        // readable), translated to currency_id here via firstOrCreate so a
        // not-yet-seen code (e.g. a mismatch test using 'USD') still gets a
        // real Currency row instead of silently resolving to null.
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
            'currency_id' => $this->egp->id,
            'is_trial' => false,
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    private function fundedWallet(User $owner, Country $country, int $spendOnlyMinor = 0): Wallet
    {
        $wallet = app(WalletService::class)->firstOrCreateWallet($owner, $country);
        $wallet->update(['spend_only_minor' => $spendOnlyMinor]);

        return $wallet->fresh();
    }

    private function purchaseService(): AiSubscriptionPurchaseService
    {
        return app(AiSubscriptionPurchaseService::class);
    }

    // ------------------------------------------------------- AiPlan::resolvedPriceFor()

    public function test_resolved_price_falls_back_to_the_plans_base_price_when_no_override_exists(): void
    {
        $plan = $this->makePlan(['price' => 100, 'original_price' => 120, 'currency' => 'EGP']);

        $resolved = $plan->resolvedPriceFor($this->saudi);

        $this->assertSame(100.0, $resolved['price']);
        $this->assertSame(120.0, $resolved['original_price']);
        $this->assertSame('EGP', $resolved['currency']);
        $this->assertFalse($resolved['is_country_specific']);
    }

    public function test_resolved_price_falls_back_when_country_is_null(): void
    {
        $plan = $this->makePlan(['price' => 100]);

        $resolved = $plan->resolvedPriceFor(null);

        $this->assertSame(100.0, $resolved['price']);
        $this->assertFalse($resolved['is_country_specific']);
    }

    public function test_resolved_price_uses_the_country_specific_override_when_one_exists(): void
    {
        $plan = $this->makePlan(['price' => 100, 'currency' => 'EGP']);

        AiPlanPrice::query()->create([
            'plan_id' => $plan->id,
            'country_id' => $this->saudi->id,
            'currency_id' => $this->sar->id,
            'price' => 45,
            'original_price' => 60,
        ]);

        $resolved = $plan->resolvedPriceFor($this->saudi);

        $this->assertSame(45.0, $resolved['price']);
        $this->assertSame(60.0, $resolved['original_price']);
        $this->assertSame('SAR', $resolved['currency']);
        $this->assertTrue($resolved['is_country_specific']);

        // Egypt (no override row) still gets the plan's own base price/currency.
        $resolvedEgypt = $plan->resolvedPriceFor($this->egypt);
        $this->assertSame(100.0, $resolvedEgypt['price']);
        $this->assertSame('EGP', $resolvedEgypt['currency']);
        $this->assertFalse($resolvedEgypt['is_country_specific']);
    }

    // ------------------------------------------------------- subscribe()/renew()/changePlan()

    public function test_subscribe_charges_the_country_specific_price_in_the_countrys_own_currency(): void
    {
        $plan = $this->makePlan(['price' => 100, 'currency' => 'EGP']);
        AiPlanPrice::query()->create([
            'plan_id' => $plan->id, 'country_id' => $this->saudi->id,
            'currency_id' => $this->sar->id, 'price' => 45,
        ]);

        $owner = $this->makeUser();
        app()->instance('resolved_country', $this->saudi);
        $wallet = $this->fundedWallet($owner, $this->saudi, spendOnlyMinor: 10000); // 100.00 SAR

        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $this->assertEquals(45.0, (float) $subscription->current_plan_price);
        $this->assertSame(5500, $wallet->fresh()->spend_only_minor); // 100 - 45 = 55.00

        $payment = AiSubscriptionPayment::query()->where('subscription_id', $subscription->id)->first();
        $this->assertSame('SAR', $payment->currency);
        $this->assertEquals(45.0, (float) $payment->amount);
    }

    public function test_subscribe_in_a_country_without_an_override_still_uses_the_base_price(): void
    {
        $plan = $this->makePlan(['price' => 100, 'currency' => 'EGP']);
        // Override only exists for Saudi Arabia - Egypt has none.
        AiPlanPrice::query()->create([
            'plan_id' => $plan->id, 'country_id' => $this->saudi->id,
            'currency_id' => $this->sar->id, 'price' => 45,
        ]);

        $owner = $this->makeUser();
        app()->instance('resolved_country', $this->egypt);
        $wallet = $this->fundedWallet($owner, $this->egypt, spendOnlyMinor: 20000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);

        $this->assertEquals(100.0, (float) $subscription->current_plan_price);
        $this->assertSame(10000, $wallet->fresh()->spend_only_minor); // 200 - 100 = 100.00
    }

    public function test_renew_uses_the_country_specific_price_of_the_subscriptions_own_wallet(): void
    {
        $plan = $this->makePlan(['price' => 100, 'currency' => 'EGP']);
        AiPlanPrice::query()->create([
            'plan_id' => $plan->id, 'country_id' => $this->saudi->id,
            'currency_id' => $this->sar->id, 'price' => 45,
        ]);

        $owner = $this->makeUser();
        $owner->update(['country_id' => $this->saudi->id]);
        app()->instance('resolved_country', $this->saudi);
        $wallet = $this->fundedWallet($owner, $this->saudi, spendOnlyMinor: 10000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $this->assertSame(5500, $wallet->fresh()->spend_only_minor);

        // renew() runs with no request/middleware context (the scheduled
        // command) - it falls back to the owner's own saved country_id and
        // must still resolve the SAR price from there, not the plan's EGP
        // base price.
        app()->forgetInstance('resolved_country');

        $ok = $this->purchaseService()->renew($subscription->fresh());

        $this->assertTrue($ok);
        $this->assertSame(1000, $wallet->fresh()->spend_only_minor); // 55 - 45 = 10.00
        $this->assertEquals(45.0, (float) $subscription->fresh()->current_plan_price);

        $renewalPayment = AiSubscriptionPayment::query()->where('subscription_id', $subscription->id)
            ->where('type', AiSubscriptionPayment::TYPE_RENEWAL)->first();
        $this->assertSame('SAR', $renewalPayment->currency);
    }

    public function test_change_plan_prorates_using_the_new_plans_country_specific_price(): void
    {
        $oldPlan = $this->makePlan(['price' => 30, 'duration_days' => 30, 'currency' => 'EGP']);
        // The Saudi wallet is SAR, so even the FIRST subscription needs a
        // Saudi override to pass assertCurrencyMatches() - only the new
        // plan's override is actually under test here.
        AiPlanPrice::query()->create([
            'plan_id' => $oldPlan->id, 'country_id' => $this->saudi->id,
            'currency_id' => $this->sar->id, 'price' => 30,
        ]);

        $newPlan = $this->makePlan(['price' => 900, 'duration_days' => 30, 'currency' => 'EGP']);
        AiPlanPrice::query()->create([
            'plan_id' => $newPlan->id, 'country_id' => $this->saudi->id,
            'currency_id' => $this->sar->id, 'price' => 90,
        ]);

        $owner = $this->makeUser();
        app()->instance('resolved_country', $this->saudi);
        $wallet = $this->fundedWallet($owner, $this->saudi, spendOnlyMinor: 100000);

        $subscription = $this->purchaseService()->subscribe($owner, $oldPlan);
        $subscription->update(['ends_at' => now()->addDays(10)]);
        $balanceAfterInitial = $wallet->fresh()->spend_only_minor;

        $updated = $this->purchaseService()->changePlan($owner, $subscription->fresh(), $newPlan);

        // new cost for remainder = 90 (SAR override) * 10/30 = 30.00, old
        // remaining value = 30 * 10/30 = 10.00 -> diff = 20.00 charged.
        $this->assertSame($balanceAfterInitial - 2000, $wallet->fresh()->spend_only_minor);
        $this->assertEquals(90.0, (float) $updated->current_plan_price);
    }

    public function test_renew_falls_back_to_the_default_country_when_the_owner_has_none_saved(): void
    {
        $plan = $this->makePlan(['price' => 40, 'currency' => 'EGP']);

        $owner = $this->makeUser(); // no country_id set
        app()->instance('resolved_country', $this->egypt);
        $wallet = $this->fundedWallet($owner, $this->egypt, spendOnlyMinor: 100000);

        $subscription = $this->purchaseService()->subscribe($owner, $plan);
        $balanceAfterInitial = $wallet->fresh()->spend_only_minor;

        // No request context and no saved country_id - must fall back to
        // the seeded default country (Egypt/EGP here), not crash.
        app()->forgetInstance('resolved_country');

        $ok = $this->purchaseService()->renew($subscription->fresh());

        $this->assertTrue($ok);
        $this->assertSame($balanceAfterInitial - 4000, $wallet->fresh()->spend_only_minor);
    }

    // ------------------------------------------------------- admin CRUD (AiPlanPriceController)

    public function test_an_unauthenticated_admin_request_is_rejected(): void
    {
        $plan = $this->makePlan();

        $this->getJson("/api/admin/v1/ai-plans/{$plan->id}/prices")->assertStatus(401);
    }

    public function test_admin_index_lists_every_active_country_merged_with_any_existing_override(): void
    {
        $this->actingAsAdmin();
        $plan = $this->makePlan(['price' => 100]);
        AiPlanPrice::query()->create([
            'plan_id' => $plan->id, 'country_id' => $this->saudi->id,
            'currency_id' => $this->sar->id, 'price' => 45,
        ]);

        $response = $this->getJson("/api/admin/v1/ai-plans/{$plan->id}/prices")->assertOk();
        $rows = collect($response->json('data.rows'));

        $this->assertCount(2, $rows, 'both active countries must appear, override or not');

        $saudiRow = $rows->firstWhere('country_id', $this->saudi->id);
        $this->assertTrue($saudiRow['is_country_specific']);
        $this->assertEquals(45, $saudiRow['price']);

        $egyptRow = $rows->firstWhere('country_id', $this->egypt->id);
        $this->assertFalse($egyptRow['is_country_specific']);
        $this->assertNull($egyptRow['price']);
    }

    public function test_admin_can_set_a_country_price_and_then_update_it(): void
    {
        $this->actingAsAdmin();
        $plan = $this->makePlan(['price' => 100]);

        $this->postJson("/api/admin/v1/ai-plans/{$plan->id}/prices", [
            'country_id' => $this->saudi->id,
            'price' => 45,
            'original_price' => 60,
        ])->assertOk();

        $this->assertSame(1, AiPlanPrice::query()->where('plan_id', $plan->id)->where('country_id', $this->saudi->id)->count());
        $row = AiPlanPrice::query()->where('plan_id', $plan->id)->where('country_id', $this->saudi->id)->first();
        $this->assertEquals(45.0, (float) $row->price);
        $this->assertSame($this->sar->id, $row->currency_id);

        // Same (plan, country) again - updates the same row instead of duplicating it.
        $this->postJson("/api/admin/v1/ai-plans/{$plan->id}/prices", [
            'country_id' => $this->saudi->id,
            'price' => 50,
        ])->assertOk();

        $this->assertSame(1, AiPlanPrice::query()->where('plan_id', $plan->id)->where('country_id', $this->saudi->id)->count());
        $this->assertEquals(50.0, (float) $row->fresh()->price);
    }

    public function test_admin_can_remove_a_country_price_override(): void
    {
        $this->actingAsAdmin();
        $plan = $this->makePlan(['price' => 100]);
        $price = AiPlanPrice::query()->create([
            'plan_id' => $plan->id, 'country_id' => $this->saudi->id,
            'currency_id' => $this->sar->id, 'price' => 45,
        ]);

        $this->deleteJson("/api/admin/v1/ai-plans/{$plan->id}/prices/{$price->id}")->assertOk();

        $this->assertSame(0, AiPlanPrice::query()->whereKey($price->id)->count());
        $this->assertEquals(100.0, $plan->resolvedPriceFor($this->saudi)['price']);
    }

    public function test_admin_cannot_delete_a_price_belonging_to_a_different_plan(): void
    {
        $this->actingAsAdmin();
        $plan = $this->makePlan();
        $otherPlan = $this->makePlan();
        $price = AiPlanPrice::query()->create([
            'plan_id' => $otherPlan->id, 'country_id' => $this->saudi->id,
            'currency_id' => $this->sar->id, 'price' => 45,
        ]);

        $this->deleteJson("/api/admin/v1/ai-plans/{$plan->id}/prices/{$price->id}")->assertStatus(404);
        $this->assertSame(1, AiPlanPrice::query()->whereKey($price->id)->count());
    }

    public function test_setting_a_price_for_an_unknown_country_is_rejected(): void
    {
        $this->actingAsAdmin();
        $plan = $this->makePlan();

        $this->postJson("/api/admin/v1/ai-plans/{$plan->id}/prices", [
            'country_id' => 999999,
            'price' => 45,
        ])->assertStatus(422);
    }

    // ------------------------------------------------------- customer-facing plans()

    public function test_plans_endpoint_returns_the_resolved_price_for_the_requesting_customers_country(): void
    {
        $plan = $this->makePlan(['price' => 100, 'currency' => 'EGP', 'is_active' => true]);
        AiPlanPrice::query()->create([
            'plan_id' => $plan->id, 'country_id' => $this->saudi->id,
            'currency_id' => $this->sar->id, 'price' => 45,
        ]);

        $user = $this->makeUser();
        Sanctum::actingAs($user, ['*'], 'user_api');

        $response = $this->getJson('/api/user/v1/ai-subscription/plans', ['X-Country' => 'SA'])->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $plan->id);

        $this->assertEquals(45, $row['price']);
        $this->assertSame('SAR', $row['currency']);
        $this->assertTrue($row['is_country_specific_price']);
    }

    public function test_plans_endpoint_falls_back_to_the_base_price_for_a_country_without_an_override(): void
    {
        $plan = $this->makePlan(['price' => 100, 'currency' => 'EGP', 'is_active' => true]);

        $user = $this->makeUser();
        Sanctum::actingAs($user, ['*'], 'user_api');

        $response = $this->getJson('/api/user/v1/ai-subscription/plans', ['X-Country' => 'EG'])->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $plan->id);

        $this->assertEquals(100, $row['price']);
        $this->assertSame('EGP', $row['currency']);
        $this->assertFalse($row['is_country_specific_price']);
    }
}
