<?php

namespace Modules\Wallet\Services;

use Illuminate\Support\Facades\DB;
use Modules\Wallet\Enums\FinancialEntryType;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Models\WalletFeeRule;
use Modules\Wallet\Support\Payments\GatewayCallbackResult;
use Modules\Wallet\Support\Payments\LogSanitizer;

/**
 * Turns a *server-verified* gateway result into wallet money — the one place a
 * payment_transactions row becomes `paid`. Every entry point (redirect
 * callback, URPay OTP confirm, admin reconcile) ends here, so they all get the
 * same guarantees:
 *
 *  - atomic: payment status, wallet rows and system income/expense entries
 *    commit together or not at all;
 *  - idempotent: the payment row is locked and only pending/expired ones
 *    complete, plus every wallet row carries a `payment:{uuid}:*` key;
 *  - the credited amount is the payment's own requested_amount_minor and the
 *    fee/bonus snapshot taken at quote time — never a gateway-reported figure
 *    and never a re-evaluated rule (docs/wallet-structure.md §9.3, #5).
 */
class PaymentCompletionService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly FinancialLedgerService $ledger,
        private readonly WalletNotifier $notifier,
    ) {}

    public function complete(PaymentTransaction $payment, GatewayCallbackResult $result): PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $result) {
            $locked = PaymentTransaction::query()->lockForUpdate()->findOrFail($payment->id);

            // Already paid/refunded/failed: a duplicate callback is a no-op.
            if (! $locked->status->canBeCompleted()) {
                return $locked;
            }

            if (! $result->confirmed) {
                return $this->fail($locked, $result);
            }

            // The gateway says it took a different amount than we asked for.
            // Never credit either figure blindly — leave it pending with a
            // reason so an admin looks at it (Online Transactions screen).
            if ($result->amountMinor !== null && $result->amountMinor !== $locked->requested_amount_minor) {
                $locked->update(['failure_reason' => 'amount_mismatch']);

                return $locked;
            }

            return $this->credit($locked, $result);
        });
    }

    private function credit(PaymentTransaction $payment, GatewayCallbackResult $result): PaymentTransaction
    {
        $owner = $payment->owner();
        $wallet = $this->wallets->firstOrCreateWallet($owner, $payment->country);

        $meta = [
            'reference_type' => 'payment_transaction',
            'reference_id' => $payment->id,
            'payment_transaction_id' => $payment->id,
            'fee_rule_id' => $payment->fee_rule_id,
            'fee_percent' => $payment->fee_percent,
        ];

        $this->wallets->credit(
            $wallet,
            $payment->requested_amount_minor,
            WalletBucket::Withdrawable,
            WalletTransactionType::Topup,
            $meta + [
                'idempotency_key' => "payment:{$payment->uuid}:topup",
                'notes' => ['key' => 'wallet.notes.topup', 'variables' => []],
            ],
        );

        $fee = $payment->quotedFeeMinor();

        if ($fee > 0) {
            $feeTransaction = $this->wallets->debit(
                $wallet,
                $fee,
                WalletBucket::Withdrawable,
                WalletTransactionType::TopupFee,
                $meta + [
                    'idempotency_key' => "payment:{$payment->uuid}:fee",
                    'notes' => ['key' => 'wallet.notes.topup_fee', 'variables' => []],
                ],
            );

            $this->ledger->record(
                'topup_fee',
                FinancialEntryType::Income,
                $fee,
                $payment->currency,
                $payment->country,
                reference: $payment,
                notes: ['key' => 'wallet.notes.topup_fee', 'variables' => []],
                walletTransaction: $feeTransaction,
            );
        }

        $bonus = (int) $payment->quoted_bonus_amount_minor;

        if ($bonus > 0) {
            // A bonus is always spend_only — never withdrawable, no matter what
            // the rule says (docs/wallet-plan.md §10/§12).
            $bonusTransaction = $this->wallets->credit(
                $wallet,
                $bonus,
                WalletBucket::SpendOnly,
                WalletTransactionType::TopupBonus,
                $meta + [
                    'idempotency_key' => "payment:{$payment->uuid}:bonus",
                    'notes' => ['key' => 'wallet.notes.topup_bonus', 'variables' => []],
                ],
            );

            $this->ledger->record(
                'promo_bonus_cost',
                FinancialEntryType::Expense,
                $bonus,
                $payment->currency,
                $payment->country,
                reference: $payment,
                notes: ['key' => 'wallet.notes.topup_bonus', 'variables' => []],
                walletTransaction: $bonusTransaction,
            );

            if ($payment->fee_rule_id !== null) {
                WalletFeeRule::query()->whereKey($payment->fee_rule_id)->increment('budget_used_minor', $bonus);
            }
        }

        $payment->update([
            'status' => PaymentTransactionStatus::Paid,
            'wallet_id' => $wallet->id,
            'gateway_reference' => $result->gatewayReference ?? $payment->gateway_reference,
            'raw_response' => LogSanitizer::redact($result->rawResponse) ?? $payment->raw_response,
            'failure_reason' => null,
            'processed_at' => now(),
        ]);

        $this->notifier->topupPaid($payment);

        return $payment;
    }

    /**
     * Only a *pending* payment can fail; an expired one stays expired.
     */
    private function fail(PaymentTransaction $payment, GatewayCallbackResult $result): PaymentTransaction
    {
        if ($payment->status !== PaymentTransactionStatus::Pending) {
            return $payment;
        }

        $payment->update([
            'status' => PaymentTransactionStatus::Failed,
            'failure_reason' => mb_substr((string) ($result->errorMessage ?? 'not_confirmed'), 0, 255),
            'raw_response' => LogSanitizer::redact($result->rawResponse) ?? $payment->raw_response,
            'processed_at' => now(),
        ]);

        $this->notifier->topupFailed($payment);

        return $payment;
    }
}
