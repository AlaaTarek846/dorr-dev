<?php

namespace Modules\Wallet\Services;

use Illuminate\Support\Facades\DB;
use Modules\Wallet\Enums\PaymentGatewayLogEvent;
use Modules\Wallet\Enums\PaymentTransactionStatus;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Exceptions\TopupException;
use Modules\Wallet\Models\FinancialEntry;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WalletFeeRule;
use Modules\Wallet\Models\WalletTransaction;

/**
 * Wallet-side refund of a paid top-up: reverses the credit, the fee and the
 * bonus in one atomic operation (a refund removes the gift too — wallet-plan
 * §12). None of the three source integrations implemented a refund call to the
 * gateway, so the money going back to the customer's card/bank is an
 * out-of-band step the admin does in the gateway's own portal.
 *
 * Only allowed while the money is still there: if the customer already spent
 * or withdrew part of it, refunding the whole top-up would push the wallet
 * into an unintended debt — that case is refused (refund_not_whole) and left
 * to a manual adjustment decision.
 */
class PaymentRefundService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly PaymentLogger $logger,
        private readonly WalletNotifier $notifier,
    ) {}

    /**
     * @param  array{type: string, id: int|null}|null  $triggeredBy
     *
     * @throws TopupException
     */
    public function refund(PaymentTransaction $payment, ?array $triggeredBy = null): PaymentTransaction
    {
        return DB::transaction(function () use ($payment, $triggeredBy) {
            $locked = PaymentTransaction::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status !== PaymentTransactionStatus::Paid) {
                throw TopupException::refundNotPaid();
            }

            $wallet = Wallet::query()->lockForUpdate()->findOrFail($locked->wallet_id);

            $rows = WalletTransaction::query()
                ->where('payment_transaction_id', $locked->id)
                ->whereIn('type', [WalletTransactionType::Topup, WalletTransactionType::TopupFee, WalletTransactionType::TopupBonus])
                ->get()
                ->keyBy(fn (WalletTransaction $row) => $row->type->value);

            $netWithdrawable = $locked->quoted_net_amount_minor ?? $locked->requested_amount_minor;
            $bonus = (int) $locked->quoted_bonus_amount_minor;

            // "Untouched" approximated by what's actually spendable now: the
            // withdrawable part and the bonus must both still be there.
            if ($wallet->availableMinor(WalletBucket::Withdrawable) < $netWithdrawable
                || ($bonus > 0 && $wallet->availableMinor(WalletBucket::SpendOnly) < $bonus)) {
                throw TopupException::refundNotWhole();
            }

            $meta = [
                'payment_transaction_id' => $locked->id,
                'notes' => ['key' => 'wallet.notes.topup_refund', 'variables' => []],
            ];

            foreach ($rows as $row) {
                $this->wallets->reverse($row, 'topup_refund', $meta);
            }

            // The system's income/cost from this top-up never happened.
            FinancialEntry::query()->whereIn('wallet_transaction_id', $rows->pluck('id'))->get()->each(fn (FinancialEntry $entry) => $entry->delete());

            if ($bonus > 0 && $locked->fee_rule_id !== null) {
                WalletFeeRule::query()->whereKey($locked->fee_rule_id)->decrement('budget_used_minor', $bonus);
            }

            $locked->update(['status' => PaymentTransactionStatus::Refunded]);

            $this->logger->record(
                $locked,
                $locked->paymentMethod->gateway,
                PaymentGatewayLogEvent::RefundAttempt,
                'outbound',
                request: ['amount_minor' => $locked->requested_amount_minor],
                gatewayStatus: 'wallet_reversal_only',
                triggeredBy: $triggeredBy,
            );

            $this->notifier->topupRefunded($locked);

            return $locked;
        });
    }
}
