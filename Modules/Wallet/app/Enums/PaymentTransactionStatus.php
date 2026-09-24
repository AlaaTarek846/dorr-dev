<?php

namespace Modules\Wallet\Enums;

enum PaymentTransactionStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Refunded = 'refunded';

    /**
     * Whether a late gateway confirmation may still complete this payment
     * (an `expired` one can be reconciled if the bank confirms it after all).
     */
    public function canBeCompleted(): bool
    {
        return in_array($this, [self::Pending, self::Expired], true);
    }
}
