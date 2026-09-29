<?php

namespace Modules\Wallet\Exceptions;

use App\Exceptions\ApiRenderable;
use RuntimeException;

/**
 * A transfer rule violation the sender can act on (or at least understand).
 * Renders itself — see ApiRenderable.
 */
class TransferException extends RuntimeException implements ApiRenderable
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

    public static function disabled(): self
    {
        return new self('transfers_disabled', 'wallet.errors.transfers_disabled', 403);
    }

    /**
     * No wallet_settings row for the country: refuse rather than guess limits (fail closed).
     */
    public static function settingsMissing(): self
    {
        return new self('wallet_settings_missing', 'wallet.errors.wallet_settings_missing', 422);
    }

    /**
     * One answer for "no such user", "other country", "inactive" and "yourself"
     * — a transfer form must not become a way to probe who has an account.
     */
    public static function recipientUnavailable(): self
    {
        return new self('transfer_recipient_unavailable', 'wallet.errors.transfer_recipient_unavailable', 422);
    }

    /**
     * A scanned QR belongs to a wallet in another country than the sender's own wallet — money can't
     * cross countries, and the code itself already says which one it is.
     */
    public static function qrOtherCountry(string $qrCountry, string $walletCountry): self
    {
        return new self('transfer_qr_other_country', 'wallet.errors.transfer_qr_other_country', 422, ['qr' => $qrCountry, 'wallet' => $walletCountry]);
    }

    /**
     * The sender typed their own phone / wallet number. Safe to say out loud — it reveals nothing
     * about anyone else — and far clearer than "no account found".
     */
    public static function toSelf(): self
    {
        return new self('transfer_to_self', 'wallet.errors.transfer_to_self', 422);
    }

    /**
     * The confirmation from the lookup step is missing, expired (10 minutes), or was
     * issued to someone else — the sender has to look the recipient up again.
     */
    public static function recipientExpired(): self
    {
        return new self('transfer_recipient_expired', 'wallet.errors.transfer_recipient_expired', 422);
    }

    /**
     * @param  'per_transaction'|'per_day'|'per_month'  $scope
     */
    public static function limitExceeded(string $scope, int $limitMinor, int $remainingMinor): self
    {
        return new self(
            'transfer_limit_exceeded',
            'wallet.errors.transfer_limit_'.$scope,
            422,
            ['limit' => $limitMinor / 100, 'remaining' => max(0, $remainingMinor) / 100],
            ['scope' => $scope, 'limit_minor' => $limitMinor, 'remaining_minor' => max(0, $remainingMinor)],
        );
    }
}
