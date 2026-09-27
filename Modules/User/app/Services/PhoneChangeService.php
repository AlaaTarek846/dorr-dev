<?php

namespace Modules\User\Services;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\User\Exceptions\PhoneChangeException;
use Modules\User\Models\User;
use Modules\User\Models\UserPhoneChange;
use Modules\User\Models\UserPhoneHistory;
use Modules\Wallet\Exceptions\PinFrozenException;
use Modules\Wallet\Exceptions\PinRequiredException;
use Modules\Wallet\Services\PinService;

/**
 * Changing the phone number used to be a plain field on the profile-update form — no proof the new
 * number belongs to the person asking, no uniqueness check, no PIN, no record of what it used to be
 * (docs/wallet-tasks.md §10.9, wallet policy bend 38). This is the guarded replacement:
 *
 *  1. {@see self::start()} — a code goes to the *new* number. If the wallet already has a PIN, the
 *     request must prove it too (X-Wallet-Pin) — a frozen wallet (see PinService::isFrozen()) refuses
 *     outright, exactly like every other sensitive action while under review.
 *  2. {@see self::confirm()} — the code proves the new number is reachable by whoever is asking; the
 *     old number becomes a permanent history row, every *other* session is signed out, and the phone
 *     itself only changes now, inside the same transaction.
 */
class PhoneChangeService
{
    private const CODE_LENGTH = 4;

    private const EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PinService $pins,
        private readonly NotificationCenter $notifications,
    ) {}

    public function start(User $user, string $newPhone, ?string $pin): void
    {
        if ($this->pins->isFrozen($user)) {
            throw new PinFrozenException;
        }

        if ($this->pins->has($user)) {
            if (! is_string($pin) || $pin === '') {
                throw new PinRequiredException;
            }

            $this->pins->verify($user, $pin);
        }

        if ($newPhone === $user->phone) {
            throw PhoneChangeException::sameNumber();
        }

        if (User::query()->where('phone', $newPhone)->where('id', '!=', $user->id)->exists()) {
            throw PhoneChangeException::alreadyTaken();
        }

        $this->assertResendAllowed($user);

        $code = $this->generateCode();

        UserPhoneChange::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'new_phone' => $newPhone,
                'code' => $code,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            ],
        );

        // No SMS gateway wired yet — same fixed-dev-code convention as login (auth_flow.phone_otp_fixed).
        $this->markSent($user);
    }

    /**
     * @throws PhoneChangeException|ValidationException
     */
    public function confirm(User $user, string $code, ?string $ip): void
    {
        if ($this->pins->isFrozen($user)) {
            throw new PinFrozenException;
        }

        $pending = UserPhoneChange::query()->where('user_id', $user->id)->first();

        if ($pending === null) {
            throw PhoneChangeException::noneStarted();
        }

        if ($pending->isExpired()) {
            $pending->delete();

            throw PhoneChangeException::expired();
        }

        if ($pending->attempts >= self::MAX_ATTEMPTS) {
            throw PhoneChangeException::tooManyAttempts();
        }

        if (! hash_equals($pending->code, $code)) {
            $pending->increment('attempts');

            throw PhoneChangeException::wrongCode();
        }

        // Re-checked at the moment of truth: someone else could have taken the number while this one waited.
        if (User::query()->where('phone', $pending->new_phone)->where('id', '!=', $user->id)->exists()) {
            $pending->delete();

            throw PhoneChangeException::alreadyTaken();
        }

        DB::transaction(function () use ($user, $pending, $ip) {
            $oldPhone = $user->phone;

            UserPhoneHistory::query()->create([
                'user_id' => $user->id,
                'old_phone' => $oldPhone,
                'new_phone' => $pending->new_phone,
                'ip_address' => $ip,
                'changed_at' => now(),
            ]);

            $newPhone = $pending->new_phone;
            $user->update(['phone' => $newPhone]);
            $pending->delete();

            // Whoever is confirming this is proven to control the new number; every *other* session
            // is signed out, the same way a lost-phone recovery would (wallet policy bend 38).
            $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();

            $this->notifications->send($user, 'user.phone.changed', 'phone_changed_title', 'phone_changed_body', ['phone' => $newPhone], ['type' => 'user_phone']);
        });
    }

    private function generateCode(): string
    {
        $fixed = (string) config('auth_flow.phone_otp_fixed', '');

        if ($fixed !== '') {
            return $fixed;
        }

        return (string) random_int(
            (int) str_pad('1', self::CODE_LENGTH, '0'),
            (int) str_pad('', self::CODE_LENGTH, '9'),
        );
    }

    private function assertResendAllowed(User $user): void
    {
        $cooldown = (int) config('auth_flow.otp_resend_cooldown_seconds', 60);

        if ($cooldown > 0 && Cache::has($this->cooldownKey($user))) {
            throw ValidationException::withMessages([
                'new_phone' => [__('api.verification_code_resend_wait', ['seconds' => $cooldown])],
            ]);
        }
    }

    private function markSent(User $user): void
    {
        $cooldown = (int) config('auth_flow.otp_resend_cooldown_seconds', 60);

        if ($cooldown > 0) {
            Cache::put($this->cooldownKey($user), true, now()->addSeconds($cooldown));
        }
    }

    private function cooldownKey(User $user): string
    {
        return 'phone_change_resend:'.$user->id;
    }
}
