<?php

namespace Modules\Wallet\Exceptions;

use Carbon\CarbonInterface;

class PinLockedException extends WalletPinException
{
    public function __construct(public readonly CarbonInterface $lockedUntil)
    {
        parent::__construct('Wallet PIN locked until '.$lockedUntil->toDateTimeString().'.');
    }

    public function translationKey(): string
    {
        return 'wallet.errors.pin_locked';
    }

    protected function translationReplace(): array
    {
        return ['minutes' => max(1, (int) ceil(now()->diffInSeconds($this->lockedUntil, false) / 60))];
    }

    public function apiStatus(): int
    {
        return 423;
    }

    public function apiErrorCode(): string
    {
        return 'wallet_pin_locked';
    }

    public function apiData(): array
    {
        return ['locked_until' => $this->lockedUntil->toISOString()];
    }
}
