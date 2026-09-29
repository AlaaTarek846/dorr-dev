<?php

namespace Modules\Wallet\Enums;

enum WalletHoldStatus: string
{
    case Active = 'active';
    case Captured = 'captured';
    case Released = 'released';
    case Expired = 'expired';

    public function isFinal(): bool
    {
        return $this !== self::Active;
    }
}
