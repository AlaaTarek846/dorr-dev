<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Policy\FreelanceLedger;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Exceptions\FinancialCategoryNotFoundException;
use Modules\Wallet\Models\FinancialCategory;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Services\FinancialLedgerService;
use Tests\TestCase;

/** FL-009: the three ledger categories of freelance work exist from the migrations, and the cover writes them right. */
class FreelanceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Currency $sar;

    private Country $saudi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => $this->sar->id,
        ]);
    }

    public function test_the_categories_are_seeded_with_their_type_and_both_languages(): void
    {
        foreach ([['freelance_commissions', 'income'], ['freelance_client_fees', 'income'], ['freelance_discounts', 'expense']] as [$slug, $type]) {
            $category = FinancialCategory::query()->where('slug', $slug)->with('translations')->firstOrFail();
            $this->assertSame($type, $category->type->value);
            $this->assertTrue((bool) $category->is_system);
            $this->assertEqualsCanonicalizing(['ar', 'en'], $category->translations->pluck('locale')->all());
        }
    }

    public function test_running_the_migration_again_does_not_duplicate_anything(): void
    {
        $migration = require base_path('Modules/Freelance/database/migrations/2026_10_27_100500_seed_freelance_financial_categories.php');
        $migration->up();
        $migration->up();

        $this->assertSame(1, FinancialCategory::query()->where('slug', 'freelance_commissions')->count());
        $this->assertSame(2, DB::table('financial_category_translations')->whereIn('financial_category_id', FinancialCategory::query()->where('slug', 'freelance_commissions')->pluck('id'))->count());
    }

    public function test_the_cover_writes_each_line_to_its_category_with_the_project_note(): void
    {
        $ledger = app(FreelanceLedger::class);
        $freelancer = Freelancer::query()->create(['phone' => '+966500000077', 'country_id' => $this->saudi->id]);

        $commission = $ledger->commission(1500, $this->sar, $this->saudi, $freelancer, 'Logo design');
        $fee = $ledger->clientFee(300, $this->sar, $this->saudi, $freelancer, 'Logo design');
        $discount = $ledger->discount(200, $this->sar, $this->saudi, $freelancer, 'Logo design');

        $this->assertSame('freelance_commissions', $commission->category->slug);
        $this->assertSame(FinancialEntryType::Income, $commission->type);
        $this->assertSame(1500, $commission->amount_minor);
        $this->assertSame($this->sar->id, $commission->currency_id);
        $this->assertSame($this->saudi->id, $commission->country_id);
        $this->assertSame($freelancer->id, $commission->reference_id);
        $this->assertSame(['key' => 'wallet.notes.freelance_commission', 'variables' => ['project' => 'Logo design']], $commission->notes);
        $this->assertSame('freelance_client_fees', $fee->category->slug);
        $this->assertSame(FinancialEntryType::Expense, $discount->type);
        $this->assertSame('freelance_discounts', $discount->category->slug);
        $this->assertSame(3, FinancialEntry::query()->count());
    }

    public function test_an_amount_of_zero_writes_nothing(): void
    {
        $ledger = app(FreelanceLedger::class);

        $this->assertNull($ledger->commission(0, $this->sar, $this->saudi, null, 'Logo'));
        $this->assertNull($ledger->clientFee(-5, $this->sar, $this->saudi, null, 'Logo'));
        $this->assertSame(0, FinancialEntry::query()->count());
    }

    public function test_the_ledger_still_refuses_the_wrong_type_and_an_unknown_category(): void
    {
        $this->expectException(\Modules\Wallet\Exceptions\FinancialEntryTypeMismatchException::class);
        app(FinancialLedgerService::class)->record('freelance_commissions', FinancialEntryType::Expense, 100, $this->sar, $this->saudi);
    }

    public function test_an_unknown_slug_is_refused(): void
    {
        $this->expectException(FinancialCategoryNotFoundException::class);
        app(FinancialLedgerService::class)->record('freelance_tips', FinancialEntryType::Income, 100, $this->sar, $this->saudi);
    }

    public function test_every_note_has_a_text_in_both_languages(): void
    {
        foreach (['freelance_commission', 'freelance_client_fee', 'freelance_discount'] as $note) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame("wallet.notes.{$note}", __("wallet.notes.{$note}", ['project' => 'X'], $locale));
                $this->assertStringContainsString('X', __("wallet.notes.{$note}", ['project' => 'X'], $locale));
            }
        }
    }
}
