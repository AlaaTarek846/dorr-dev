<?php

namespace Modules\Wallet\Exceptions;

class PinMismatchException extends WalletPinException
{
    public function __construct()
    {
        parent::__construct('Wallet PIN mismatch.');
    }

    public function translationKey(): string
    {
        return 'wallet.errors.pin_invalid';
    }

    public function apiStatus(): int
    {
        return 422;
    }

    public function apiErrorCode(): string
    {
        return 'wallet_pin_invalid';
    }
}
