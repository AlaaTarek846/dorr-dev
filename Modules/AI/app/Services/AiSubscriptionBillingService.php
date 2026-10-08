<?php

namespace Modules\AI\Services;

use App\Models\Country;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiSubscriptionException;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\InsufficientBalanceException;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletService;

/**
 * The only door between the AI subscription system and the Wallet module -
 * everything else (AiSubscriptionPurchaseService) goes through this, never
 * WalletService directly, so the money-handling rules below live in one
 * place.
 *
 * Bucket split (docs/wallet-structure.md line ~278, the project's own
 * proposed rule for splitting a debit across both buckets): spend_only is
 * charged first, withdrawable covers the rest - in the owner's favor
 * (spends the money that could never be withdrawn anyway before touching
 * what could), and it is what the wallet schema's own doc already
 * recommends for exactly this situation. Both resulting wallet_transactions
 * rows share one operation_id, matching that same doc's convention for a
 * split debit.
 *
 * WalletService::debit() only guards spend_only from going negative -
 * withdrawable has no such guard at that layer on purpose (an allowed-debt
 * decision belongs to a later WalletEligibilityService phase, not
 * WalletService). A subscription purchase is a real product decision that
 * must never silently put an owner's wallet into debt, so this class does
 * its own combined-availability check before ever calling debit(), inside
 * the same row lock, and refuses (InsufficientBalanceException) rather than
 * ever letting withdrawable go negative here.
 */
class AiSubscriptionBillingService
{
    public function __construct(protected WalletService $wallets) {}

    /**
     * $country lets a caller resolve the wallet for a SPECIFIC country
     * instead of relying on currentCountry() (only ever bound by the
     * 'country' HTTP middleware) - required by AiSubscriptionPurchaseService
     * ::renew(), which runs from the scheduled console command with no
     * request/middleware context at all. Falls back to currentCountry()
     * when no explicit country is given, unchanged from before for every
     * request-context caller (subscribe(), changePlan()).
     */
    public function walletFor(Authenticatable $owner, ?Country $country = null): Wallet
    {
        $country ??= currentCountry();

        if ($country === null) {
            throw new AiSubscriptionException('subscription_country_not_resolved');
        }

        return $this->wallets->firstOrCreateWallet($owner, $country);
    }

    public function assertCurrencyMatches(Wallet $wallet, string $planCurrency): void
    {
        $walletCurrency = $wallet->currency?->code;

        if ($walletCurrency !== null && strtoupper($walletCurrency) !== strtoupper($planCurrency)) {
            throw new AiSubscriptionException('subscription_currency_mismatch');
        }
    }

    /**
     * Charges $amount (decimal, in the wallet's own currency - callers must
     * have already run assertCurrencyMatches()) from the owner's wallet,
     * spend_only first then withdrawable. Throws InsufficientBalanceException
     * (bucket 'combined') when neither bucket, even combined, covers it -
     * never partially charges.
     *
     * @return array{transactions: list<WalletTransaction>, operation_id: string}
     */
    public function charge(Wallet $wallet, float $amount, WalletTransactionType $type, array $meta = []): array
    {
        $amountMinor = $this->toMinor($wallet, $amount);

        return DB::transaction(function () use ($wallet, $amountMinor, $type, $meta) {
            $locked = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

            $spendAvailable = max(0, $locked->availableMinor(WalletBucket::SpendOnly));
            $withdrawableAvailable = max(0, $locked->availableMinor(WalletBucket::Withdrawable));

            if ($spendAvailable + $withdrawableAvailable < $amountMinor) {
                throw new InsufficientBalanceException($locked->id, 'combined');
            }

            $operationId = (string) Str::uuid();
            $transactions = [];

            $spendPortion = min($amountMinor, $spendAvailable);

            if ($spendPortion > 0) {
                $transactions[] = $this->wallets->debit($locked, $spendPortion, WalletBucket::SpendOnly, $type, [
                    ...$meta,
                    'operation_id' => $operationId,
                ]);
            }

            $withdrawablePortion = $amountMinor - $spendPortion;

            if ($withdrawablePortion > 0) {
                $transactions[] = $this->wallets->debit($locked, $withdrawablePortion, WalletBucket::Withdrawable, $type, [
                    ...$meta,
                    'operation_id' => $operationId,
                ]);
            }

            return ['transactions' => $transactions, 'operation_id' => $operationId];
        });
    }

    /**
     * Credits $amount back to the owner's wallet (always spend_only - the
     * receiving-side rule WalletService::transfer() already follows
     * elsewhere in this module) - used for a downgrade's prorated refund.
     */
    public function credit(Wallet $wallet, float $amount, WalletTransactionType $type, array $meta = []): WalletTransaction
    {
        $amountMinor = $this->toMinor($wallet, $amount);

        return $this->wallets->credit($wallet, $amountMinor, WalletBucket::SpendOnly, $type, $meta);
    }

    public function toMinor(Wallet $wallet, float $amount): int
    {
        $decimals = $wallet->currency?->decimal_places ?? 2;

        return (int) round($amount * (10 ** $decimals));
    }
}
