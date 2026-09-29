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
use Modules\Wallet\Database\Seeders\FinancialCategorySeeder;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 7 — the customer statement and the admin wallet/ledger screens.
 */
class WalletStatementTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Country $egypt;

    private Provider $me;

    private Provider $other;

    private WalletService $wallets;

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

        $this->seed(FinancialCategorySeeder::class);

        $this->wallets = app(WalletService::class);
        $this->me = Provider::create(['name' => 'Mona Ali', 'email' => 'mona@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);
        $this->other = Provider::create(['name' => 'Other Person', 'email' => 'other@example.com', 'country_id' => $this->saudi->id, 'status' => 'active']);

        Sanctum::actingAs($this->me, [], 'provider_api');
    }

    private function walletOf(Provider $owner, ?Country $country = null): Wallet
    {
        return $this->wallets->firstOrCreateWallet($owner, $country ?? $this->saudi);
    }

    private function statement(array $query = [], array $headers = ['X-Country' => 'SA']): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/provider/v1/wallet/transactions?'.http_build_query($query), $headers);
    }

    // ---------------------------------------------------------- customer side

    public function test_the_statement_lists_my_rows_newest_first_with_translated_labels(): void
    {
        $wallet = $this->walletOf($this->me);
        $this->wallets->credit($wallet, 10000, WalletBucket::Withdrawable, WalletTransactionType::Topup, ['notes' => ['key' => 'wallet.notes.topup', 'variables' => []]]);
        $this->wallets->credit($wallet, 500, WalletBucket::SpendOnly, WalletTransactionType::TopupBonus, ['notes' => ['key' => 'wallet.notes.topup_bonus', 'variables' => []]]);

        $rows = $this->statement()->assertOk()->json('data');

        $this->assertSame(['topup_bonus', 'topup'], array_column($rows, 'type'));
        $this->assertSame('Top-up bonus', $rows[0]['type_label']);
        $this->assertSame('Top-up bonus', $rows[0]['note']);
        $this->assertSame('spend_only', $rows[0]['bucket']);
        $this->assertSame(500, $rows[0]['balance_after_minor']);
        $this->assertSame(10500, $rows[0]['total_balance_after_minor']);
    }

    public function test_someone_elses_rows_never_appear(): void
    {
        $this->wallets->credit($this->walletOf($this->other), 9999, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $this->wallets->credit($this->walletOf($this->me), 100, WalletBucket::Withdrawable, WalletTransactionType::Topup);

        $rows = $this->statement()->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame(100, $rows[0]['amount_minor']);
    }

    public function test_an_owner_without_a_wallet_has_an_empty_statement_not_an_error(): void
    {
        $this->statement()->assertOk()->assertJsonPath('data', []);
    }

    public function test_the_statement_is_scoped_to_the_requests_country(): void
    {
        $this->wallets->credit($this->walletOf($this->me, $this->saudi), 100, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $this->wallets->credit($this->walletOf($this->me, $this->egypt), 777, WalletBucket::Withdrawable, WalletTransactionType::Topup);

        $sa = $this->statement(headers: ['X-Country' => 'SA'])->json('data');
        $eg = $this->statement(headers: ['X-Country' => 'EG'])->json('data');

        $this->assertSame([100], array_column($sa, 'amount_minor'));
        $this->assertSame([777], array_column($eg, 'amount_minor'));
    }

    public function test_the_balance_mentions_wallets_in_other_countries_read_only(): void
    {
        $this->wallets->credit($this->walletOf($this->me, $this->saudi), 100, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $this->wallets->credit($this->walletOf($this->me, $this->egypt), 777, WalletBucket::Withdrawable, WalletTransactionType::Topup);

        $this->getJson('/api/provider/v1/wallet', ['X-Country' => 'SA'])
            ->assertOk()
            ->assertJsonPath('data.total_minor', 100)
            ->assertJsonPath('data.other_wallets.0.country_code', 'EG')
            ->assertJsonPath('data.other_wallets.0.currency_code', 'EGP')
            ->assertJsonPath('data.other_wallets.0.total_minor', 777);
    }

    public function test_filters_narrow_the_statement(): void
    {
        $wallet = $this->walletOf($this->me);
        $this->wallets->credit($wallet, 1000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $this->wallets->credit($wallet, 200, WalletBucket::SpendOnly, WalletTransactionType::TopupBonus);
        $this->wallets->debit($wallet, 300, WalletBucket::Withdrawable, WalletTransactionType::Withdrawal);
        WalletTransaction::query()->where('type', WalletTransactionType::Topup)->update(['created_at' => now()->subDays(10)]);

        $this->assertCount(1, $this->statement(['bucket' => 'spend_only'])->json('data'));
        $this->assertCount(1, $this->statement(['direction' => 'debit'])->json('data'));
        $this->assertCount(1, $this->statement(['type' => 'topup'])->json('data'));
        $this->assertCount(2, $this->statement(['from' => now()->subDays(2)->toDateString()])->json('data'));
        $this->assertCount(1, $this->statement(['to' => now()->subDays(5)->toDateString()])->json('data'));
        $this->statement(['bucket' => 'nonsense'])->assertStatus(422);
    }

    public function test_pagination_reports_more_pages(): void
    {
        $wallet = $this->walletOf($this->me);
        foreach (range(1, 12) as $i) {
            $this->wallets->credit($wallet, $i * 100, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        }

        $page1 = $this->statement(['per_page' => 5])->assertOk();
        $page2 = $this->statement(['per_page' => 5, 'page' => 2])->assertOk();

        $this->assertTrue($page1->json('pagination.has_more_pages'));
        $this->assertCount(5, $page2->json('data'));
        $this->assertNotSame($page1->json('data.0.uuid'), $page2->json('data.0.uuid'));
    }

    // -------------------------------------------------------------- admin side

    private function admin(array $permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    public function test_admin_finds_wallets_by_owner_name_and_filters_by_country(): void
    {
        $this->walletOf($this->me);
        $this->walletOf($this->other);
        $this->walletOf($this->me, $this->egypt);
        $this->admin(['wallets.view']);

        $byName = $this->getJson('/api/admin/v1/wallets?search=Mona')->assertOk()->json('data');
        $this->assertCount(2, $byName);
        $this->assertSame(['Mona Ali'], array_values(array_unique(array_column(array_column($byName, 'owner'), 'name'))));

        $this->assertCount(1, $this->getJson('/api/admin/v1/wallets?search=Mona&country_id='.$this->egypt->id)->json('data'));
    }

    public function test_admin_reads_a_wallets_statement(): void
    {
        $wallet = $this->walletOf($this->me);
        $this->wallets->credit($wallet, 1000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $this->admin(['wallets.view']);

        $this->getJson("/api/admin/v1/wallets/{$wallet->id}")->assertOk()->assertJsonPath('data.withdrawable_minor', 1000);
        $this->getJson("/api/admin/v1/wallets/{$wallet->id}/transactions")->assertOk()->assertJsonPath('data.0.amount_minor', 1000);
    }

    public function test_a_credit_adjustment_is_an_attributed_row_and_a_system_expense(): void
    {
        $wallet = $this->walletOf($this->me);
        $admin = $this->admin(['wallets.manual-adjustment']);

        $this->postJson("/api/admin/v1/wallets/{$wallet->id}/adjustments", [
            'direction' => 'credit', 'bucket' => 'spend_only', 'amount_minor' => 2500, 'reason' => 'Goodwill for a delayed order',
        ])->assertCreated()->assertJsonPath('data.type', 'manual_adjustment');

        $this->assertSame(2500, $wallet->fresh()->spend_only_minor);
        $tx = WalletTransaction::query()->firstOrFail();
        $this->assertSame('admin', $tx->created_by_type);
        $this->assertSame($admin->id, $tx->created_by_id);
        $this->assertSame('Manual adjustment (Goodwill for a delayed order)', __($tx->notes['key'], $tx->notes['variables']));

        $entry = FinancialEntry::query()->with('category')->firstOrFail();
        $this->assertSame('manual_adjustment_expense', $entry->category->slug);
        $this->assertSame(2500, $entry->amount_minor);
        $this->assertSame($tx->id, $entry->wallet_transaction_id);
    }

    public function test_a_debit_adjustment_is_a_system_income(): void
    {
        $wallet = $this->walletOf($this->me);
        $this->wallets->credit($wallet, 5000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
        $this->admin(['wallets.manual-adjustment']);

        $this->postJson("/api/admin/v1/wallets/{$wallet->id}/adjustments", [
            'direction' => 'debit', 'bucket' => 'withdrawable', 'amount_minor' => 1200, 'reason' => 'Chargeback',
        ])->assertCreated();

        $this->assertSame(3800, $wallet->fresh()->withdrawable_minor);
        $this->assertSame('manual_adjustment_income', FinancialEntry::query()->with('category')->firstOrFail()->category->slug);
    }

    public function test_a_failed_adjustment_leaves_no_ledger_or_system_entry_behind(): void
    {
        $wallet = $this->walletOf($this->me);
        $this->admin(['wallets.manual-adjustment']);

        // spend_only can never go negative.
        $this->postJson("/api/admin/v1/wallets/{$wallet->id}/adjustments", [
            'direction' => 'debit', 'bucket' => 'spend_only', 'amount_minor' => 100, 'reason' => 'Too much',
        ])->assertStatus(422)->assertJsonPath('error_code', 'insufficient_balance');

        $this->assertSame(0, WalletTransaction::query()->count());
        $this->assertSame(0, FinancialEntry::query()->count());
    }

    public function test_bucket_and_reason_are_mandatory_for_an_adjustment(): void
    {
        $wallet = $this->walletOf($this->me);
        $this->admin(['wallets.manual-adjustment']);
        $url = "/api/admin/v1/wallets/{$wallet->id}/adjustments";

        $this->postJson($url, ['direction' => 'credit', 'amount_minor' => 100, 'reason' => 'x good'])->assertJsonValidationErrors('bucket');
        $this->postJson($url, ['direction' => 'credit', 'bucket' => 'withdrawable', 'amount_minor' => 100])->assertJsonValidationErrors('reason');
        $this->postJson($url, ['direction' => 'credit', 'bucket' => 'withdrawable', 'amount_minor' => 0, 'reason' => 'zero'])->assertJsonValidationErrors('amount_minor');
    }

    public function test_wallet_admin_endpoints_need_their_permissions(): void
    {
        $wallet = $this->walletOf($this->me);
        $this->admin(['wallets.view']);

        $this->getJson('/api/admin/v1/wallets')->assertOk();
        $this->postJson("/api/admin/v1/wallets/{$wallet->id}/adjustments", [
            'direction' => 'credit', 'bucket' => 'withdrawable', 'amount_minor' => 100, 'reason' => 'nope nope',
        ])->assertForbidden();
        $this->getJson('/api/admin/v1/financial-entries')->assertForbidden();
    }

    public function test_the_financial_ledger_summarises_per_currency(): void
    {
        $sa = $this->walletOf($this->me, $this->saudi);
        $eg = $this->walletOf($this->me, $this->egypt);
        $this->admin(['wallets.manual-adjustment', 'financial-entries.view']);

        $adjust = fn (Wallet $w, string $direction, int $amount) => $this->postJson("/api/admin/v1/wallets/{$w->id}/adjustments", [
            'direction' => $direction, 'bucket' => 'withdrawable', 'amount_minor' => $amount, 'reason' => 'ledger test',
        ])->assertCreated();

        $adjust($sa, 'credit', 3000); // expense in SAR
        $adjust($sa, 'debit', 1000);  // income in SAR
        $adjust($eg, 'credit', 500);  // expense in EGP

        $summary = collect($this->getJson('/api/admin/v1/financial-entries/summary')->assertOk()->json('data'))->keyBy('currency_code');

        $this->assertSame(['income_minor' => 1000, 'expense_minor' => 3000, 'net_minor' => -2000], collect($summary['SAR'])->only(['income_minor', 'expense_minor', 'net_minor'])->all());
        $this->assertSame(500, $summary['EGP']['expense_minor']);

        $this->assertCount(3, $this->getJson('/api/admin/v1/financial-entries')->json('data'));
        $this->assertCount(2, $this->getJson('/api/admin/v1/financial-entries?type=expense')->json('data'));
        $this->assertCount(1, $this->getJson('/api/admin/v1/financial-entries?category=manual_adjustment_income')->json('data'));
    }
}
