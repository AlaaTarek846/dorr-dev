<?php

namespace Modules\Wallet\Enums;

/**
 * docs/wallet-structure.md §2. Not a closed set forever — later phases add
 * referral_reward / discount_compensation / etc. once those flows exist.
 */
enum WalletTransactionType: string
{
    case Topup = 'topup';
    case TopupFee = 'topup_fee';
    case TopupBonus = 'topup_bonus';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Withdrawal = 'withdrawal';
    case Refund = 'refund';
    case Penalty = 'penalty';
    case ServicePayment = 'service_payment';
    case ServiceEarning = 'service_earning';
    case ManualAdjustment = 'manual_adjustment';
    case Reversal = 'reversal';
}
