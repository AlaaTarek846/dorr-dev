<?php

namespace Modules\Wallet\Exceptions;

/**
 * The wallet PIN is permanently frozen: a wrong attempt right after a temporary (15-minute) lock had
 * already been served once. Distinct from {@see PinLockedException} — a temporary lock lifts itself; this
 * one doesn't. The only way out is {@see \Modules\Wallet\Services\WalletRecoveryService::requestSecurityUnfreeze()}
 * (a selfie + an ID photo, reviewed by a person) — self-service recovery (password/birth date/e-mail/document)
 * is refused too while frozen, since the freeze exists precisely because whoever is holding the phone
 * couldn't prove they know the PIN.
 */
class PinFrozenException extends WalletPinException
{
    public function __construct()
    {
        parent::__construct('Wallet PIN permanently frozen.');
    }

    public function translationKey(): string
    {
        return 'wallet.errors.pin_frozen';
    }

    public function apiStatus(): int
    {
        return 423;
    }

    public function apiErrorCode(): string
    {
        return 'wallet_pin_frozen';
    }
}
