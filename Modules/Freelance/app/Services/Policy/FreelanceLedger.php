<?php

namespace Modules\Freelance\Services\Policy;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\FinancialLedgerService;

/**
 * The platform's ledger lines for freelance work (FL-009): the commission kept from the freelancer, the fee
 * the client pays on top, and the discounts the platform bears. A thin cover over FinancialLedgerService so
 * the payment tasks name the money, not the category slugs. An amount of zero writes nothing (the ledger
 * refuses it, and there is nothing to record).
 *
 * `$reference` is the thing the money belongs to (the milestone, later); `$project` is its title for the
 * ledger note shown to the admin.
 */
class FreelanceLedger
{
    public const COMMISSIONS = 'freelance_commissions';

    public const CLIENT_FEES = 'freelance_client_fees';

    public const DISCOUNTS = 'freelance_discounts';

    public function __construct(private readonly FinancialLedgerService $ledger) {}

    public function commission(int $amountMinor, Currency $currency, ?Country $country, ?Model $reference, string $project, ?WalletTransaction $walletTransaction = null): ?FinancialEntry
    {
        return $this->write(self::COMMISSIONS, FinancialEntryType::Income, $amountMinor, $currency, $country, $reference, 'freelance_commission', $project, $walletTransaction);
    }

    public function clientFee(int $amountMinor, Currency $currency, ?Country $country, ?Model $reference, string $project, ?WalletTransaction $walletTransaction = null): ?FinancialEntry
    {
        return $this->write(self::CLIENT_FEES, FinancialEntryType::Income, $amountMinor, $currency, $country, $reference, 'freelance_client_fee', $project, $walletTransaction);
    }

    public function discount(int $amountMinor, Currency $currency, ?Country $country, ?Model $reference, string $project, ?WalletTransaction $walletTransaction = null): ?FinancialEntry
    {
        return $this->write(self::DISCOUNTS, FinancialEntryType::Expense, $amountMinor, $currency, $country, $reference, 'freelance_discount', $project, $walletTransaction);
    }

    private function write(string $slug, FinancialEntryType $type, int $amountMinor, Currency $currency, ?Country $country, ?Model $reference, string $note, string $project, ?WalletTransaction $walletTransaction): ?FinancialEntry
    {
        if ($amountMinor <= 0) {
            return null;
        }

        return $this->ledger->record(
            $slug,
            $type,
            $amountMinor,
            $currency,
            $country,
            $reference,
            notes: ['key' => 'wallet.notes.'.$note, 'variables' => ['project' => $project]],
            walletTransaction: $walletTransaction,
        );
    }
}
