<?php

namespace App\Enums;

enum VerificationType: string
{
    case Phone = 'phone';
    case Email = 'email';
    /** The e-mail code that confirms a wallet-PIN recovery address / proves a forgotten PIN. */
    case WalletRecovery = 'wallet_recovery';
    /** Proves the phone on file is reachable from a device the wallet has never opened from before. */
    case DeviceTrust = 'device_trust';
}
