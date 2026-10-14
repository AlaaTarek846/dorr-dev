<?php

namespace Modules\Rides\Enums;

/**
 * How the rider pays. Card = the app's one payment screen (a wallet top-up through a gateway),
 * then the same hold as a wallet ride — so card and wallet rides end the same way.
 */
enum RidePaymentMethod: string
{
    case Wallet = 'wallet';
    case Card = 'card';
    case Cash = 'cash';

    /** The money is held in the rider's wallet and captured at the end. */
    public function holdsInWallet(): bool
    {
        return $this !== self::Cash;
    }
}
