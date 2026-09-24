<?php

namespace Modules\Wallet\Exceptions;

class PinAlreadySetException extends WalletPinException
{
    public function __construct()
    {
        parent::__construct('Wallet PIN already exists.');
    }

    public function translationKey(): string
    {
        return 'wallet.errors.pin_already_set';
    }

    public function apiStatus(): int
    {
        return 409;
    }

    public function apiErrorCode(): string
    {
        return 'wallet_pin_already_set';
    }
}
