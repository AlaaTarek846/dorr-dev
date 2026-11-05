<?php

namespace Modules\Moving\Services\Payment;

use App\Models\Country;
use Modules\Moving\Models\MovingRequest;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\FinancialLedgerService;
use Modules\Wallet\Services\WalletService;

/**
 * Moving the held money of a job, in the only ways the rules allow: take part of it from the customer (the rest goes
 * back on its own), pay the establishment, keep a part held for a report, give everything back, and write Dorr's
 * commission and the tax in the ledger. Every movement has its own key, so a retry moves nothing twice.
 */
class MovingMoney
{
    public const COMMISSIONS = 'moving_commissions';

    public const TAXES = 'moving_taxes';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly MovingPayments $payments,
        private readonly FinancialLedgerService $ledger,
    ) {}

    /**
     * Takes `$amount` from the customer's holds (spend-only first); whatever is left in them goes back to the customer.
     */
    public function take(MovingRequest $request, int $amount, string $step): ?WalletTransaction
    {
        $remaining = $amount;
        $last = null;
        foreach ($this->payments->activeHolds($request) as $hold) {
            if ($remaining <= 0) {
                $this->wallets->release($hold, 'moving_'.$step);

                continue;
            }
            $take = min($remaining, (int) $hold->amount_minor);
            $last = $this->wallets->capture($hold, $take, WalletTransactionType::ServicePayment, [
                'reference_type' => MovingPayments::REFERENCE,
                'reference_id' => $request->id,
                'notes' => $this->note('moving_payment', $request),
                'idempotency_key' => "moving:{$request->uuid}:{$step}:{$hold->id}",
            ]);
            $remaining -= $take;
        }

        return $last;
    }

    /** Gives every held amount back to the customer. Returns what was given back. */
    public function refundAll(MovingRequest $request, string $step): int
    {
        $held = $this->payments->held($request);
        foreach ($this->payments->activeHolds($request) as $hold) {
            $this->wallets->release($hold, 'moving_'.$step);
        }

        return $held;
    }

    /** Keeps `$amount` of the customer's money held for a report (REQ-052), after the rest was taken. */
    public function holdForClaim(MovingRequest $request, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $wallet = $this->wallets->firstOrCreateWallet($request->user, $this->country($request));
        foreach ($wallet->spendParts($amount) as [$bucket, $part]) {
            if ($part <= 0) {
                continue;
            }
            $this->wallets->hold($wallet, $part, $bucket, [
                'reference_type' => MovingPayments::REFERENCE,
                'reference_id' => $request->id,
                'reason_code' => 'moving_claim',
                'expires_at' => null,
                'idempotency_key' => "moving:{$request->uuid}:claim:{$bucket->value}",
            ]);
            $wallet->refresh();
        }
    }

    /** Pays the establishment into its own wallet (withdrawable). */
    public function payEstablishment(MovingRequest $request, int $amount, string $step, string $noteKey = 'moving_earning'): ?WalletTransaction
    {
        if ($amount <= 0) {
            return null;
        }
        $wallet = $this->wallets->firstOrCreateWallet($request->establishment, $this->country($request));

        return $this->wallets->credit($wallet, $amount, WalletBucket::Withdrawable, WalletTransactionType::ServiceEarning, [
            'reference_type' => MovingPayments::REFERENCE,
            'reference_id' => $request->id,
            'notes' => $this->note($noteKey, $request),
            'idempotency_key' => "moving:{$request->uuid}:{$step}:earning",
        ]);
    }

    /** Dorr's commission and the tax, as income in the ledger. */
    public function book(MovingRequest $request, ?WalletTransaction $transaction): void
    {
        $country = $this->country($request);
        foreach ([[self::COMMISSIONS, (int) $request->commission_minor, 'moving_commission'], [self::TAXES, (int) $request->tax_minor, 'moving_tax']] as [$slug, $amount, $note]) {
            if ($amount <= 0) {
                continue;
            }
            $this->ledger->record($slug, FinancialEntryType::Income, $amount, $country->currency, $country, $request,
                notes: $this->note($note, $request), walletTransaction: $transaction);
        }
    }

    /** @return array{key: string, variables: array<string, string>} */
    private function note(string $key, MovingRequest $request): array
    {
        return ['key' => 'wallet.notes.'.$key, 'variables' => ['ref' => strtoupper(substr($request->uuid, 0, 8))]];
    }

    private function country(MovingRequest $request): Country
    {
        return Country::query()->with('currency')->findOrFail($request->country_id);
    }
}
