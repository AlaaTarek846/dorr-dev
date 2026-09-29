<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * A withdrawal rule violation the owner or admin can act on. Renders itself
 * (status, translated message, machine-readable code) — see ApiRenderable.
 */
class WithdrawalException extends RuntimeException implements ApiRenderable
{
    /**
     * @param  array<string, mixed>  $replace
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $errorCode,
        private readonly string $translationKey,
        private readonly int $status = 422,
        private readonly array $replace = [],
        private readonly array $data = [],
    ) {
        parent::__construct($errorCode);
    }

    public function apiStatus(): int
    {
        return $this->status;
    }

    public function apiMessage(): string
    {
        return __($this->translationKey, $this->replace);
    }

    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }

    public function apiData(): array
    {
        return $this->data;
    }

    public static function amountOutOfRange(?int $minMinor, ?int $maxMinor): self
    {
        return new self(
            'withdrawal_amount_out_of_range',
            'wallet.errors.amount_out_of_range',
            422,
            ['min' => $minMinor === null ? '-' : $minMinor / 100, 'max' => $maxMinor === null ? '-' : $maxMinor / 100],
            ['min_minor' => $minMinor, 'max_minor' => $maxMinor],
        );
    }

    public static function pendingExists(): self
    {
        return new self('withdrawal_pending_exists', 'wallet.errors.withdrawal_pending_exists', 409);
    }

    public static function methodUnavailable(): self
    {
        return new self('withdrawal_method_unavailable', 'wallet.errors.withdrawal_method_unavailable', 422);
    }

    public static function notPending(): self
    {
        return new self('withdrawal_not_pending', 'wallet.errors.withdrawal_not_pending', 409);
    }

    /**
     * No wallet_settings row for the country: refuse rather than guess limits
     * (fail closed — docs/wallet-structure.md §3.1).
     */
    public static function settingsMissing(): self
    {
        return new self('wallet_settings_missing', 'wallet.errors.wallet_settings_missing', 422);
    }

    public static function notFound(): self
    {
        return new self('withdrawal_not_found', 'wallet.errors.withdrawal_not_found', 404);
    }

    public static function noReceipt(): self
    {
        return new self('withdrawal_no_receipt', 'wallet.errors.withdrawal_no_receipt', 404);
    }
}
