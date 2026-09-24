<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * Same idempotency_key, different request_hash — docs/wallet-structure.md §0.
 * Never retry it automatically and never silently reuse the old result:
 * something upstream is reusing a key incorrectly.
 */
class IdempotencyConflictException extends RuntimeException implements ApiRenderable
{
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct("idempotency_key '{$idempotencyKey}' was already used with a different request.");
    }

    public function apiStatus(): int
    {
        return 409;
    }

    public function apiMessage(): string
    {
        return __('wallet.errors.idempotency_conflict');
    }

    public function apiErrorCode(): string
    {
        return 'idempotency_conflict';
    }

    public function apiData(): array
    {
        return [];
    }
}
