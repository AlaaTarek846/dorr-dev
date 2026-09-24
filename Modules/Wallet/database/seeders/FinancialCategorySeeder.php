<?php

namespace Modules\Wallet\Database\Seeders;

use Database\Seeders\Concerns\SyncsSeedTranslations;
use Illuminate\Database\Seeder;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Models\FinancialCategory;

/**
 * The system-level financial_categories the wallet core needs from day one
 * (docs/wallet-tasks.md Phase 4). Everything else (trip commissions,
 * referral rewards...) arrives with the booking/order module later.
 *
 * Deviation from the plan's literal 4-slug list, found while implementing:
 * `manual_adjustment` alone can't satisfy the "type must match the
 * category's type" rule (an adjustment can be either direction). Split into
 * `manual_adjustment_income` / `manual_adjustment_expense` instead of
 * weakening that rule.
 */
class FinancialCategorySeeder extends Seeder
{
    use SyncsSeedTranslations;

    public function run(): void
    {
        foreach ($this->definitions() as $data) {
            $category = FinancialCategory::query()->updateOrCreate(
                ['slug' => $data['slug']],
                ['type' => $data['type'], 'is_system' => true, 'status' => true],
            );

            $this->syncTranslations($category, $data['name']);
        }
    }

    /**
     * @return list<array{slug: string, type: FinancialEntryType, name: array{en: string, ar: string}}>
     */
    private function definitions(): array
    {
        return [
            [
                'slug' => 'topup_fee',
                'type' => FinancialEntryType::Income,
                'name' => ['en' => 'Top-up Fee', 'ar' => 'رسوم الشحن'],
            ],
            [
                'slug' => 'promo_bonus_cost',
                'type' => FinancialEntryType::Expense,
                'name' => ['en' => 'Promotional Bonus Cost', 'ar' => 'تكلفة هدايا الشحن الترويجية'],
            ],
            [
                // Placeholder type — whether this is ever populated, and
                // whether withdrawals ever carry a fee at all, is still open
                // (wallet-plan.md §13's open question #7).
                'slug' => 'withdrawal_processing',
                'type' => FinancialEntryType::Expense,
                'name' => ['en' => 'Withdrawal Processing Cost', 'ar' => 'تكلفة معالجة السحب'],
            ],
            [
                'slug' => 'manual_adjustment_income',
                'type' => FinancialEntryType::Income,
                'name' => ['en' => 'Manual Adjustment (Credit)', 'ar' => 'تسوية يدوية (إضافة)'],
            ],
            [
                'slug' => 'manual_adjustment_expense',
                'type' => FinancialEntryType::Expense,
                'name' => ['en' => 'Manual Adjustment (Debit)', 'ar' => 'تسوية يدوية (خصم)'],
            ],
        ];
    }
}
