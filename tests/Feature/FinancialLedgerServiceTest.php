<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\FinancialCategoryNotFoundException;
use Modules\Wallet\Exceptions\FinancialEntryTypeMismatchException;
use Modules\Wallet\Models\FinancialCategory;
use Modules\Wallet\Models\FinancialEntry;
use Modules\User\Models\User;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\Wallet\Services\WalletService;
use Tests\TestCase;

class FinancialLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialLedgerService $ledger;

    private Currency $currency;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(FinancialLedgerService::class);

        $flag = Flag::create(['code' => 'sa']);
        $this->currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);
        $this->country = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $this->currency->id, 'status' => true,
        ]);

        $this->seed(\Modules\Wallet\Database\Seeders\FinancialCategorySeeder::class);
    }

    public function test_records_an_entry_against_a_known_category(): void
    {
        $entry = $this->ledger->record('topup_fee', FinancialEntryType::Income, 500, $this->currency, $this->country);

        $this->assertInstanceOf(FinancialEntry::class, $entry);
        $this->assertSame(500, $entry->amount_minor);
        $this->assertSame(FinancialEntryType::Income, $entry->type);
        $this->assertSame('topup_fee', $entry->category->slug);
    }

    public function test_unknown_slug_throws_instead_of_falling_back_to_a_default_category(): void
    {
        $this->expectException(FinancialCategoryNotFoundException::class);

        $this->ledger->record('does_not_exist', FinancialEntryType::Income, 100, $this->currency);
    }

    public function test_type_must_match_the_categorys_own_type(): void
    {
        $this->expectException(FinancialEntryTypeMismatchException::class);

        // topup_fee is an income category.
        $this->ledger->record('topup_fee', FinancialEntryType::Expense, 100, $this->currency);
    }

    public function test_a_system_category_cannot_be_deleted(): void
    {
        $category = FinancialCategory::query()->where('slug', 'topup_fee')->firstOrFail();

        $this->expectException(LogicException::class);
        $category->delete();
    }

    public function test_a_system_categorys_slug_cannot_be_changed(): void
    {
        $category = FinancialCategory::query()->where('slug', 'topup_fee')->firstOrFail();

        $this->expectException(LogicException::class);
        $category->update(['slug' => 'renamed']);
    }

    public function test_entry_can_link_back_to_the_wallet_transaction_it_came_from(): void
    {
        $user = User::create(['name' => 'U', 'phone' => '966500000009', 'country_id' => $this->country->id, 'status' => 'active']);
        $wallets = app(WalletService::class);
        $wallet = $wallets->firstOrCreateWallet($user, $this->country);
        $transaction = $wallets->credit($wallet, 1000, WalletBucket::Withdrawable, WalletTransactionType::Topup);

        $entry = $this->ledger->record(
            categorySlug: 'topup_fee',
            type: FinancialEntryType::Income,
            amountMinor: 10,
            currency: $this->currency,
            country: $this->country,
            walletTransaction: $transaction,
        );

        $this->assertSame($transaction->id, $entry->walletTransaction->id);
    }
}
