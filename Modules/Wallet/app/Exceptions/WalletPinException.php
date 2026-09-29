<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * Base type so callers (the RequiresWalletPin middleware, PIN endpoints) can
 * catch every PIN failure with one catch block; each subclass fixes its own
 * HTTP status and machine-readable code.
 */
abstract class WalletPinException extends RuntimeException implements ApiRenderable
{
    abstract public function translationKey(): string;

    /**
     * @return array<string, mixed>
     */
    protected function translationReplace(): array
    {
        return [];
    }

    public function apiMessage(): string
    {
        return __($this->translationKey(), $this->translationReplace());
    }

    public function apiData(): array
    {
        return [];
    }
}
