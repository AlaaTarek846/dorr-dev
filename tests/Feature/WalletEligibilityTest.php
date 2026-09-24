<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Provider\Models\Provider;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\NotEligibleException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletSetting;
use Modules\Wallet\Services\WalletEligibilityService;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 9 — the debt limit (wallet-plan.md §14).
 */
class WalletEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Country $egypt;

    private WalletService $wallets;

    private WalletEligibilityService $eligibility;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR']);
        $egp = Currency::create(['code' => 'EGP', 'symbol' => 'EGP']);
        $flagSa = Flag::create(['code' => 'sa']);
        $flagEg = Flag::create(['code' => 'eg']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flagSa->id, 'currency_id' => $sar->id, 'status' => true]);
        $this->egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => false, 'flag_id' => $flagEg->id, 'currency_id' => $egp->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flagSa->id]);

        $this->wallets = app(WalletService::class);
        $this->eligibility = app(WalletEligibilityService::class);
    }

    private function provider(string $name, ?int $balance = null, ?Country $country = null): Provider
    {
        $provider = Provider::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);

        if ($balance !== null) {
            $this->setBalance($provider, $balance, $country ?? $this->saudi);
        }

        return $provider;
    }

    /**
     * Debt is a negative withdrawable balance — exactly what a penalty or an
     * uncollected commission produces.
     */
    private function setBalance(Provider|User $owner, int $withdrawable, Country $country, int $spendOnly = 0): void
    {
        $wallet = $this->wallets->firstOrCreateWallet($owner, $country);

        if ($withdrawable > 0) {
            $this->wallets->credit($wallet, $withdrawable, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        } elseif ($withdrawable < 0) {
            $this->wallets->debit($wallet, -$withdrawable, WalletBucket::Withdrawable, WalletTransactionType::Penalty);
        }

        if ($spendOnly > 0) {
            $this->wallets->credit($wallet, $spendOnly, WalletBucket::SpendOnly, WalletTransactionType::TopupBonus);
        }
    }

    private function limits(Country $country, ?int $provider = null, ?int $user = null): void
    {
        WalletSetting::query()->where('country_id', $country->id)->update(array_filter([
            'min_allowed_balance_provider_minor' => $provider,
            'min_allowed_balance_user_minor' => $user,
        ], fn ($v) => $v !== null));
    }

    // ---------------------------------------------------------------- the rule

    public function test_a_debt_up_to_the_limit_is_allowed_and_one_unit_beyond_is_not(): void
    {
        $this->limits($this->saudi, provider: -10000); // up to 100.00 of debt

        $atLimit = $this->provider('AtLimit', -10000);
        $beyond = $this->provider('Beyond', -10001);

        $this->assertTrue($this->eligibility->check($atLimit, $this->saudi)->eligible);

        $result = $this->eligibility->check($beyond, $this->saudi);
        $this->assertFalse($result->eligible);
        $this->assertSame(1, $result->shortfallMinor, 'top up exactly what is missing');
        $this->assertSame(-10001, $result->balanceMinor);
    }

    public function test_a_zero_limit_means_no_debt_at_all(): void
    {
        $this->limits($this->saudi, provider: 0);

        $this->assertTrue($this->eligibility->check($this->provider('Fresh'), $this->saudi)->eligible, 'no wallet = balance 0');
        $this->assertTrue($this->eligibility->check($this->provider('Zero', 0), $this->saudi)->eligible);

        $result = $this->eligibility->check($this->provider('Debtor', -2500), $this->saudi);
        $this->assertFalse($result->eligible);
        $this->assertSame(2500, $result->shortfallMinor);
    }

    public function test_users_and_providers_have_their_own_limits(): void
    {
        $this->limits($this->saudi, provider: -10000, user: 0);

        $user = User::create(['name' => 'U', 'phone' => '966500000009', 'country_id' => $this->saudi->id, 'status' => 'active']);
        $this->setBalance($user, -3000, $this->saudi); // a penalty
        $provider = $this->provider('P', -3000);

        $this->assertFalse($this->eligibility->check($user, $this->saudi)->eligible);
        $this->assertTrue($this->eligibility->check($provider, $this->saudi)->eligible);
    }

    public function test_spend_only_money_counts_towards_the_balance(): void
    {
        $this->limits($this->saudi, provider: 0);

        // −30.00 withdrawable but 50.00 of bonus: total +20.00 → allowed.
        $provider = $this->provider('Mixed');
        $this->setBalance($provider, -3000, $this->saudi, spendOnly: 5000);

        $result = $this->eligibility->check($provider, $this->saudi);

        $this->assertTrue($result->eligible);
        $this->assertSame(2000, $result->balanceMinor);
    }

    public function test_a_debt_in_one_country_never_blocks_another(): void
    {
        $this->limits($this->saudi, provider: 0);
        $this->limits($this->egypt, provider: 0);
        $provider = $this->provider('Multi', -9000, $this->saudi);

        $this->assertFalse($this->eligibility->check($provider, $this->saudi)->eligible);
        $this->assertTrue($this->eligibility->check($provider, $this->egypt)->eligible);
    }

    public function test_missing_settings_fail_closed_with_no_silent_default(): void
    {
        WalletSetting::query()->where('country_id', $this->saudi->id)->delete();

        $result = $this->eligibility->check($this->provider('Anyone', 100000), $this->saudi);

        $this->assertFalse($result->eligible);
        $this->assertSame('settings_missing', $result->reason);
    }

    public function test_the_locked_wallet_passed_in_is_the_one_checked(): void
    {
        $this->limits($this->saudi, provider: 0);
        $provider = $this->provider('Racer', 5000);
        $wallet = Wallet::query()->where('owner_id', $provider->id)->firstOrFail();

        // The balance drops after a first check; the re-check inside the lock must see it.
        $this->assertTrue($this->eligibility->check($provider, $this->saudi, $wallet)->eligible);
        $this->wallets->debit($wallet, 8000, WalletBucket::Withdrawable, WalletTransactionType::Penalty);

        $this->assertFalse($this->eligibility->check($provider, $this->saudi, $wallet->fresh())->eligible);
    }

    public function test_assert_eligible_throws_a_403_with_the_numbers_the_app_needs(): void
    {
        $this->limits($this->saudi, provider: 0);
        $provider = $this->provider('Blocked', -4000);

        try {
            $this->eligibility->assertEligible($provider, $this->saudi);
            $this->fail('expected NotEligibleException');
        } catch (NotEligibleException $e) {
            $this->assertSame(403, $e->apiStatus());
            $this->assertSame('wallet_not_eligible', $e->apiErrorCode());
            $this->assertSame(4000, $e->apiData()['shortfall_minor']);
            $this->assertStringContainsString('40', $e->apiMessage());
        }
    }

    // ------------------------------------------------------ dispatch filtering

    public function test_the_dispatch_filter_is_one_query_that_matches_the_per_provider_check(): void
    {
        $this->limits($this->saudi, provider: -10000);
        $none = $this->provider('NoWallet');
        $ok = $this->provider('Ok', 500);
        $deep = $this->provider('Deep', -20000);
        $otherCountry = $this->provider('OnlyEgypt', -99999, $this->egypt); // debt elsewhere

        $ids = $this->eligibility->filterEligibleProviders(Provider::query(), $this->saudi)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$none->id, $ok->id, $otherCountry->id], $ids);
        $this->assertNotContains($deep->id, $ids);

        foreach (Provider::all() as $p) {
            $this->assertSame($this->eligibility->check($p, $this->saudi)->eligible, in_array($p->id, $ids, true), "filter and check disagree for {$p->name}");
        }
    }

    public function test_a_positive_requirement_excludes_providers_without_a_wallet(): void
    {
        // (the limit can't be set positive through the admin API, but the filter must still be correct)
        $this->limits($this->saudi, provider: 100);
        $none = $this->provider('NoWallet');
        $rich = $this->provider('Rich', 500);

        $ids = $this->eligibility->filterEligibleProviders(Provider::query(), $this->saudi)->pluck('id')->all();

        $this->assertSame([$rich->id], $ids);
        $this->assertNotContains($none->id, $ids);
    }

    public function test_the_dispatch_filter_fails_closed_without_settings(): void
    {
        $this->provider('Anyone', 100);
        WalletSetting::query()->where('country_id', $this->saudi->id)->delete();

        $this->assertSame(0, $this->eligibility->filterEligibleProviders(Provider::query(), $this->saudi)->count());
    }

    // ------------------------------------------------------------ the endpoint

    public function test_the_app_can_ask_why_it_is_suspended_and_how_much_to_top_up(): void
    {
        $this->limits($this->saudi, provider: -1000);
        $provider = $this->provider('Suspended', -5000);
        Sanctum::actingAs($provider, [], 'provider_api');

        $this->getJson('/api/provider/v1/wallet/eligibility', ['X-Country' => 'SA'])
            ->assertOk()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason', 'below_limit')
            ->assertJsonPath('data.balance_minor', -5000)
            ->assertJsonPath('data.shortfall_minor', 4000);
    }

    // ------------------------------------------------------------------- admin

    private function admin(array $permissions): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    public function test_admin_reads_and_updates_a_countrys_limits_and_it_takes_effect(): void
    {
        $this->admin(['wallet-settings.view', 'wallet-settings.update']);
        $provider = $this->provider('Debtor', -5000);
        $this->assertFalse($this->eligibility->check($provider, $this->saudi)->eligible, 'default limit is 0');

        $this->getJson('/api/admin/v1/wallet-settings')->assertOk()->assertJsonCount(2, 'data');
        $this->putJson("/api/admin/v1/wallet-settings/{$this->saudi->id}", [
            'min_allowed_balance_provider_minor' => -10000, 'min_withdrawal_minor' => 2000, 'max_withdrawal_minor' => 50000,
        ])->assertOk()->assertJsonPath('data.min_allowed_balance_provider_minor', -10000);

        $this->assertTrue($this->eligibility->check($provider, $this->saudi)->eligible);
    }

    public function test_a_debt_limit_can_never_be_positive_and_ranges_must_make_sense(): void
    {
        $this->admin(['wallet-settings.update']);
        $url = "/api/admin/v1/wallet-settings/{$this->saudi->id}";

        $this->putJson($url, ['min_allowed_balance_provider_minor' => 500])->assertJsonValidationErrors('min_allowed_balance_provider_minor');
        $this->putJson($url, ['min_withdrawal_minor' => 5000, 'max_withdrawal_minor' => 1000])->assertJsonValidationErrors('max_withdrawal_minor');
    }

    public function test_settings_endpoints_need_their_permissions(): void
    {
        $this->admin(['wallet-settings.view']);

        $this->getJson('/api/admin/v1/wallet-settings')->assertOk();
        $this->putJson("/api/admin/v1/wallet-settings/{$this->saudi->id}", ['transfers_enabled' => true])->assertForbidden();
    }
}
