<?php

namespace Modules\Wallet\Exceptions;

/**
 * The request needed a PIN and the client sent none (no X-Wallet-Pin header).
 * Distinct from PinNotSetException: here the PIN exists, the client just
 * hasn't asked the user for it yet — 422 so the app shows the PIN prompt.
 */
class PinRequiredException extends WalletPinException
{
    public function __construct()
    {
        parent::__construct('Wallet PIN required.');
    }

    public function translationKey(): string
    {
        return 'wallet.errors.pin_required';
    }

    public function apiStatus(): int
    {
        return 422;
    }

    public function apiErrorCode(): string
    {
        return 'wallet_pin_required';
    }
}
