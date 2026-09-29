<?php

namespace Modules\Wallet\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Exceptions\PinFrozenException;
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
 * attempt limit + lockout below, not the hash itself. Two stages, not one:
 * a wrong PIN twice earns a 15-minute *temporary* lock (which lifts itself);
 * a wrong PIN right after that lock has already been served earns a
 * *permanent* freeze that only a person can lift (see PinFrozenException).
 */
class PinService
{
    private const MAX_ATTEMPTS = 2;

    private const LOCK_MINUTES = 15;

    public function has(Model $owner): bool
    {
        return $this->find($owner) !== null;
    }

    /**
     * The PIN was reset to a well-known value (approved recovery request) and still has to be replaced.
     */
    public function mustChange(Model $owner): bool
    {
        return $this->find($owner)?->must_change === true;
    }

    /**
     * The end of a temporary lock (too many wrong PINs), while it lasts — so the app can show the
     * countdown straight away on reopening, instead of the keypad until the next wrong try.
     */
    public function lockedUntil(Model $owner): ?\Illuminate\Support\Carbon
    {
        $until = $this->find($owner)?->locked_until;

        return $until !== null && $until->isFuture() ? $until : null;
    }

    /** Permanently locked — see PinFrozenException. Lifted only by {@see WalletRecoveryService::approve()}. */
    public function isFrozen(Model $owner): bool
    {
        return $this->find($owner)?->frozen_at !== null;
    }

    /**
     * Creates the PIN on first use, or overwrites it on an explicit change —
     * callers are responsible for requiring the *old* PIN before calling this
     * for a change (this method itself doesn't distinguish create vs change).
     *
     * [$mustChange] marks a PIN that was reset to a well-known value: it opens the wallet but nothing
     * that moves money, until the owner sets a real one (any later set() without the flag clears it).
     */
    public function set(Model $owner, string $pin, bool $mustChange = false): WalletPin
    {
        return WalletPin::query()->updateOrCreate(
            [
                'owner_type' => OwnerType::aliasFor($owner),
                'owner_id' => $owner->getKey(),
            ],
            [
                'pin_hash' => $this->hash($pin),
                'must_change' => $mustChange,
                'failed_attempts' => 0,
                'locked_until' => null,
                // A freshly-set PIN — by creation, self-recovery, or an admin-approved freeze review —
                // is definitionally no longer the thing the freeze was protecting against.
                'frozen_at' => null,
                'changed_at' => now(),
            ],
        );
    }

    /**
     * @throws PinNotSetException     no PIN exists yet — caller should offer PIN creation instead
     * @throws PinFrozenException     permanently locked — needs a person to clear it
     * @throws PinLockedException     temporarily locked (lifts itself)
     * @throws PinMismatchException   wrong PIN (also registers the failure)
     */
    public function verify(Model $owner, string $pin): void
    {
        $record = $this->find($owner);

        if ($record === null) {
            throw new PinNotSetException;
        }

        if ($record->frozen_at !== null) {
            throw new PinFrozenException;
        }

        if ($record->locked_until !== null && $record->locked_until->isFuture()) {
            throw new PinLockedException($record->locked_until);
        }

        if (! password_verify($pin.$this->pepper(), $record->pin_hash)) {
            $this->registerFailure($record, $owner);

            // registerFailure() just mutated $record in place — its current state says what this
            // particular wrong attempt actually did: nothing yet, started a temporary lock, or froze it.
            if ($record->frozen_at !== null) {
                throw new PinFrozenException;
            }

            if ($record->locked_until !== null) {
                throw new PinLockedException($record->locked_until);
            }

            throw new PinMismatchException;
        }

        if ($record->failed_attempts > 0 || $record->locked_until !== null) {
            $record->update(['failed_attempts' => 0, 'locked_until' => null]);
        }
    }

    private function registerFailure(WalletPin $record, Model $owner): void
    {
        // A temporary lock has already been served once (it may or may not still be in effect — either
        // way, a fresh wrong attempt after it was set is the second strike): freeze for good.
        if ($record->locked_until !== null) {
            $record->update(['failed_attempts' => 0, 'locked_until' => null, 'frozen_at' => now()]);

            app(WalletNotifier::class)->pinFrozen($owner);

            return;
        }

        $attempts = $record->failed_attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $record->update([
                'failed_attempts' => $attempts,
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
