<?php

namespace Modules\Wallet\Support\Payments;

use Modules\Wallet\Models\WalletFeeRule;

/**
 * What a top-up would credit, computed once and then *snapshotted* onto the
 * payment_transactions row — the gateway callback later uses the snapshot,
 * never re-evaluates the rule (docs/wallet-plan.md §12: the rule may have
 * changed or the campaign ended in between).
 *
 *  - fee   (percent > 0): wallet gets `requested`, then a `topup_fee` debit —
 *          net withdrawable = requested − fee.
 *  - bonus (percent < 0): wallet gets `requested` withdrawable PLUS a
 *          spend_only bonus — never withdrawable, ever.
 */
final class TopupQuote
{
    public function __construct(
        public readonly int $requestedAmountMinor,
        public readonly ?WalletFeeRule $rule,
        public readonly string $percent,
        public readonly int $feeMinor,
        public readonly int $bonusMinor,
    ) {}

    public function netWithdrawableMinor(): int
    {
        return $this->requestedAmountMinor - $this->feeMinor;
    }

    public function totalCreditedMinor(): int
    {
        return $this->netWithdrawableMinor() + $this->bonusMinor;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'paid_amount_minor' => $this->requestedAmountMinor,
            'fee_minor' => $this->feeMinor,
            'bonus_minor' => $this->bonusMinor,
            'withdrawable_minor' => $this->netWithdrawableMinor(),
            'spend_only_minor' => $this->bonusMinor,
            'total_credited_minor' => $this->totalCreditedMinor(),
            'percent' => $this->percent,
            'fee_rule_id' => $this->rule?->id,
        ];
    }
}
