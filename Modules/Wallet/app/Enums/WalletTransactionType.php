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
    // A prize (DORR Sports contests): always spend_only — spent in the app, never withdrawn.
    case Reward = 'reward';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case TransferFee = 'transfer_fee';
    case Withdrawal = 'withdrawal';
    case Refund = 'refund';
    case Penalty = 'penalty';
    case ServicePayment = 'service_payment';
    case ServiceEarning = 'service_earning';
    case ManualAdjustment = 'manual_adjustment';
    case Reversal = 'reversal';
    // Rides (docs/rides-structure.md §10): the app's commission and the tax on it, taken from a
    // driver after a cash trip; a rider's tip; a coupon's value paid back to the driver; an old debt
    // a driver collected in cash and settled on the rider's wallet.
    case Commission = 'commission';
    case Tax = 'tax';
    case Tip = 'tip';
    case DiscountCompensation = 'discount_compensation';
    case DebtSettlement = 'debt_settlement';
}
