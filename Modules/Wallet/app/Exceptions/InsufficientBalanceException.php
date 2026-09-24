<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * Thrown when a debit/hold would push spend_only below zero, or a hold would
 * exceed what's available in the chosen bucket (bucket_minor − held_minor).
 * Never thrown for withdrawable going negative — that's an allowed-debt
 * decision for a later WalletEligibilityService, not this layer.
 */
class InsufficientBalanceException extends RuntimeException implements ApiRenderable
{
    public function __construct(public readonly int $walletId, public readonly string $bucket)
    {
        parent::__construct("Insufficient {$bucket} balance on wallet {$walletId}.");
    }

    public function apiStatus(): int
    {
        return 422;
    }

    public function apiMessage(): string
    {
        return __('wallet.errors.insufficient_balance');
    }

    public function apiErrorCode(): string
    {
        return 'insufficient_balance';
    }

    public function apiData(): array
    {
        return ['bucket' => $this->bucket];
    }
}
