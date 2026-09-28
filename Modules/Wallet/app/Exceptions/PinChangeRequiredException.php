<?php

namespace Modules\Wallet\Exceptions;

/**
 * The PIN was reset to a well-known value (after an approved recovery request) and has not been replaced
 * yet: nothing that moves money is allowed until the owner picks a new one. 403 + a stable code so the app
 * sends the person to the "choose a new PIN" screen.
 */
class PinChangeRequiredException extends WalletPinException
{
    public function __construct()
    {
        parent::__construct('Wallet PIN must be changed first.');
    }

    public function translationKey(): string
    {
        return 'wallet.errors.pin_change_required';
    }

    public function apiStatus(): int
    {
        return 403;
    }

    public function apiErrorCode(): string
    {
        return 'wallet_pin_change_required';
    }
}
