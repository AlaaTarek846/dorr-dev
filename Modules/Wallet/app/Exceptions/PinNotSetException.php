<?php

namespace Modules\Wallet\Exceptions;

/**
 * Thrown when an operation requiring a PIN is attempted and the owner has
 * none yet. 428 (Precondition Required) + `wallet_pin_not_set`: the client
 * treats this as "start PIN creation", not as a plain error — the lazy
 * creation flow in docs/wallet-tasks.md §6.1.
 */
class PinNotSetException extends WalletPinException
{
    public function __construct()
    {
        parent::__construct('Wallet PIN not set.');
    }

    public function translationKey(): string
    {
        return 'wallet.errors.pin_not_set';
    }

    public function apiStatus(): int
    {
        return 428;
    }

    public function apiErrorCode(): string
    {
        return 'wallet_pin_not_set';
    }
}
