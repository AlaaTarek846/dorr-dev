<?php

namespace Modules\Wallet\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Exceptions\PinLockedException;
use Modules\Wallet\Exceptions\PinMismatchException;
use Modules\Wallet\Exceptions\PinNotSetException;
use Modules\Wallet\Models\WalletPin;
use Modules\Wallet\Support\OwnerType;

/**
 * Financial PIN, separate from login — required before topup/transfer/service
 * payment/withdrawal/withdrawal-method changes (docs/wallet-structure.md §1.2).
 *
 * A 4-digit PIN is only 10,000 combinations, so the real protection is the
 * attempt limit + temporary lockout below, not the hash itself.
 */
class PinService
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_MINUTES = 15;

    public function has(Model $owner): bool
    {
        return $this->find($owner) !== null;
    }

    /**
     * Creates the PIN on first use, or overwrites it on an explicit change —
     * callers are responsible for requiring the *old* PIN before calling this
     * for a change (this method itself doesn't distinguish create vs change).
     */
    public function set(Model $owner, string $pin): WalletPin
    {
        return WalletPin::query()->updateOrCreate(
            [
                'owner_type' => OwnerType::aliasFor($owner),
                'owner_id' => $owner->getKey(),
            ],
            [
                'pin_hash' => $this->hash($pin),
                'failed_attempts' => 0,
                'locked_until' => null,
                'changed_at' => now(),
            ],
        );
    }

    /**
     * @throws PinNotSetException     no PIN exists yet — caller should offer PIN creation instead
     * @throws PinLockedException     too many recent failures
     * @throws PinMismatchException   wrong PIN (also registers the failure)
     */
    public function verify(Model $owner, string $pin): void
    {
        $record = $this->find($owner);

        if ($record === null) {
            throw new PinNotSetException;
        }

        if ($record->locked_until !== null && $record->locked_until->isFuture()) {
            throw new PinLockedException($record->locked_until);
        }

        if (! password_verify($pin.$this->pepper(), $record->pin_hash)) {
            $this->registerFailure($record, $owner);

            throw new PinMismatchException;
        }

        if ($record->failed_attempts > 0 || $record->locked_until !== null) {
            $record->update(['failed_attempts' => 0, 'locked_until' => null]);
        }
    }

    private function registerFailure(WalletPin $record, Model $owner): void
    {
        $attempts = $record->failed_attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $record->update([
                'failed_attempts' => 0,
                'locked_until' => now()->addMinutes(self::LOCK_MINUTES),
            ]);

            // Someone guessing at the PIN is exactly what the real owner needs to hear about.
            app(WalletNotifier::class)->pinLocked($owner, self::LOCK_MINUTES);

            return;
        }

        $record->update(['failed_attempts' => $attempts]);
    }

    private function find(Model $owner): ?WalletPin
    {
        return WalletPin::query()
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->first();
    }

    private function hash(string $pin): string
    {
        return password_hash($pin.$this->pepper(), PASSWORD_ARGON2ID);
    }

    private function pepper(): string
    {
        return (string) config('wallet.pin_pepper');
    }
}
