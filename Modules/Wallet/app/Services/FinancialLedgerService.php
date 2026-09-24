<?php

namespace Modules\Wallet\Services;

use App\Models\Country;
use App\Models\Currency;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Exceptions\FinancialCategoryNotFoundException;
use Modules\Wallet\Exceptions\FinancialEntryTypeMismatchException;
use Modules\Wallet\Models\FinancialCategory;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\WalletTransaction;

/**
 * The system's own income/expense ledger — separate from wallet_transactions
 * (which belongs to a specific owner). This is the only door: no
 * FinancialEntry::create() anywhere else (docs/wallet-plan.md §13).
 */
class FinancialLedgerService
{
    /**
     * @param  array{key: string, variables?: array<string, mixed>}|null  $notes
     * @param  array{type: string, id: int|null}|null  $createdBy
     *
     * @throws FinancialCategoryNotFoundException  unknown slug — no silent fallback (unlike Jawad's hardcoded category_id => 1)
     * @throws FinancialEntryTypeMismatchException  $type doesn't match the category's own type
     */
    public function record(
        string $categorySlug,
        FinancialEntryType $type,
        int $amountMinor,
        Currency $currency,
        ?Country $country = null,
        ?Model $reference = null,
        ?array $notes = null,
        ?WalletTransaction $walletTransaction = null,
        ?string $description = null,
        ?array $createdBy = null,
        ?CarbonInterface $entryDate = null,
    ): FinancialEntry {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('amount_minor must be greater than zero.');
        }

        $category = FinancialCategory::query()->where('slug', $categorySlug)->first();

        if ($category === null) {
            throw new FinancialCategoryNotFoundException($categorySlug);
        }

        if ($category->type !== $type) {
            throw new FinancialEntryTypeMismatchException($categorySlug, $category->type, $type);
        }

        return DB::transaction(fn () => FinancialEntry::query()->create([
            'category_id' => $category->id,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'currency_id' => $currency->id,
            'country_id' => $country?->id,
            'entry_date' => ($entryDate ?? now())->toDateString(),
            'description' => $description,
            'notes' => $notes,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'wallet_transaction_id' => $walletTransaction?->id,
            'created_by_type' => $createdBy['type'] ?? null,
            'created_by_id' => $createdBy['id'] ?? null,
        ]));
    }
}
