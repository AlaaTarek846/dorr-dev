<?php

namespace Modules\Wallet\Services;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Enums\WalletDirection;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WithdrawalRequest;
use Modules\Wallet\Support\MaskedName;

/**
 * Tells people what happened to their wallet — one method per event, all through
 * NotificationCenter (in-app list + real-time + push, in every language).
 *
 * What goes into a notification: amounts and *masked* names only (the same rule as the transfer
 * screens — another person's name is never shown in full), and ids in the data payload so the app
 * can open the right screen. Never a PIN, never a full phone number, never a payment credential.
 */
class WalletNotifier
{
    public function __construct(private readonly NotificationCenter $center) {}

    // ---------------------------------------------------------------- top-ups

    public function topupPaid(PaymentTransaction $payment): void
    {
        $owner = $payment->owner();
        $currency = $payment->currency?->code;
        $bonus = (int) $payment->quoted_bonus_amount_minor;
        $credited = $payment->requested_amount_minor - $payment->quotedFeeMinor();

        $this->center->send(
            $owner,
            'wallet.topup.paid',
            'wallet_topup_paid_title',
            $bonus > 0 ? 'wallet_topup_paid_bonus_body' : 'wallet_topup_paid_body',
            ['amount' => $this->money($credited, $currency), 'bonus' => $this->money($bonus, $currency)],
            ['type' => 'wallet', 'payment_uuid' => $payment->uuid],
        );
    }

    public function topupFailed(PaymentTransaction $payment): void
    {
        $this->center->send(
            $payment->owner(),
            'wallet.topup.failed',
            'wallet_topup_failed_title',
            'wallet_topup_failed_body',
            ['amount' => $this->money($payment->requested_amount_minor, $payment->currency?->code)],
            ['type' => 'wallet', 'payment_uuid' => $payment->uuid],
        );
    }

    public function topupRefunded(PaymentTransaction $payment): void
    {
        $this->center->send(
            $payment->owner(),
            'wallet.topup.refunded',
            'wallet_topup_refunded_title',
            'wallet_topup_refunded_body',
            ['amount' => $this->money($payment->requested_amount_minor, $payment->currency?->code)],
            ['type' => 'wallet', 'payment_uuid' => $payment->uuid],
        );
    }

    // ---------------------------------------------------------------- transfers

    /**
     * Both sides hear about it: the sender gets a receipt, the recipient gets the money news —
     * each only sees the *other* person's masked name.
     */
    public function transfer(Wallet $from, Wallet $to, int $amountMinor): void
    {
        $currency = $from->currency?->code ?? $from->loadMissing('currency')->currency?->code;
        $sender = $from->owner();
        $recipient = $to->owner();
        $amount = $this->money($amountMinor, $currency);

        $this->center->send(
            $sender,
            'wallet.transfer.sent',
            'wallet_transfer_sent_title',
            'wallet_transfer_sent_body',
            ['amount' => $amount, 'name' => MaskedName::of($recipient?->name)],
            ['type' => 'wallet', 'direction' => WalletDirection::Debit->value],
        );

        $this->center->send(
            $recipient,
            'wallet.transfer.received',
            'wallet_transfer_received_title',
            'wallet_transfer_received_body',
            ['amount' => $amount, 'name' => MaskedName::of($sender?->name)],
            ['type' => 'wallet', 'direction' => WalletDirection::Credit->value],
        );
    }

    // ---------------------------------------------------------------- PIN

    public function pinCreated(Model $owner): void
    {
        $this->center->send($owner, 'wallet.pin.created', 'wallet_pin_created_title', 'wallet_pin_created_body', [], ['type' => 'wallet_pin']);
    }

    public function pinChanged(Model $owner): void
    {
        $this->center->send($owner, 'wallet.pin.changed', 'wallet_pin_changed_title', 'wallet_pin_changed_body', [], ['type' => 'wallet_pin']);
    }

    public function pinLocked(Model $owner, int $minutes): void
    {
        $this->center->send($owner, 'wallet.pin.locked', 'wallet_pin_locked_title', 'wallet_pin_locked_body', ['minutes' => $minutes], ['type' => 'wallet_pin']);
    }

    // ---------------------------------------------------------------- withdrawals

    /**
     * The owner gets an acknowledgement; whoever may approve withdrawals gets something to act on.
     */
    public function withdrawalRequested(WithdrawalRequest $request): void
    {
        $wallet = $request->wallet()->with('currency')->first();
        $owner = $wallet?->owner();
        $amount = $this->money($request->amount_minor, $wallet?->currency?->code);

        $this->center->send(
            $owner,
            'wallet.withdrawal.requested',
            'wallet_withdrawal_requested_title',
            'wallet_withdrawal_requested_body',
            ['amount' => $amount],
            ['type' => 'wallet_withdrawal', 'withdrawal_id' => $request->id],
        );

        // Staff see who asked (they're allowed to; it's their job), still no payout details.
        $this->center->send(
            $this->center->adminsWith('withdrawal-requests.approve'),
            'wallet.withdrawal.review',
            'wallet_withdrawal_review_title',
            'wallet_withdrawal_review_body',
            ['amount' => $amount, 'name' => (string) ($owner?->name ?: ($owner?->phone ?? '#'.$owner?->getKey()))],
            ['type' => 'wallet_withdrawal', 'withdrawal_id' => $request->id],
            push: false,
        );
    }

    public function withdrawalPaid(WithdrawalRequest $request): void
    {
        $wallet = $request->wallet()->with('currency')->first();

        $this->center->send(
            $wallet?->owner(),
            'wallet.withdrawal.paid',
            'wallet_withdrawal_paid_title',
            'wallet_withdrawal_paid_body',
            ['amount' => $this->money($request->amount_minor, $wallet?->currency?->code)],
            ['type' => 'wallet_withdrawal', 'withdrawal_id' => $request->id],
        );
    }

    public function withdrawalRejected(WithdrawalRequest $request, string $reason): void
    {
        $wallet = $request->wallet()->with('currency')->first();

        $this->center->send(
            $wallet?->owner(),
            'wallet.withdrawal.rejected',
            'wallet_withdrawal_rejected_title',
            'wallet_withdrawal_rejected_body',
            ['amount' => $this->money($request->amount_minor, $wallet?->currency?->code), 'reason' => $reason],
            ['type' => 'wallet_withdrawal', 'withdrawal_id' => $request->id],
        );
    }

    // ---------------------------------------------------------------- admin actions

    public function adjusted(Wallet $wallet, WalletDirection $direction, int $amountMinor, string $reason): void
    {
        $wallet->loadMissing('currency');
        $credit = $direction === WalletDirection::Credit;

        $this->center->send(
            $wallet->owner(),
            'wallet.adjusted.'.$direction->value,
            $credit ? 'wallet_adjusted_credit_title' : 'wallet_adjusted_debit_title',
            $credit ? 'wallet_adjusted_credit_body' : 'wallet_adjusted_debit_body',
            ['amount' => $this->money($amountMinor, $wallet->currency?->code), 'reason' => $reason],
            ['type' => 'wallet'],
        );
    }

    private function money(int $minor, ?string $currency): string
    {
        return trim(number_format($minor / 100, 2, '.', '').' '.$currency);
    }
}
