<?php

namespace App\Traits;

use App\Enums\VerificationType;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Sends a phone OTP for any model that uses HasVerificationCodes
 * (works against the `verification_codes` table via the model's
 * `verificationCodes()` morph-many relation).
 */
trait SendsPhoneOtp
{
    /**
     * Create a new unverified phone OTP record for the model (fixed demo code).
     */
    public function sendPhoneOtp(bool $force = false): VerificationCode
    {
        if (! $force) {
            $this->assertPhoneOtpResendAllowed();
        }

        $this->verificationCodes()
            ->where('type', VerificationType::Phone->value)
            ->whereNull('verified_at')
            ->delete();

        $code = $this->phoneOtpCode();

        $verificationCode = $this->verificationCodes()->create([
            'type' => VerificationType::Phone->value,
            'code' => $code,
            'expires_at' => now()->addMinutes((int) config('auth_flow.otp_expiry_minutes', 10)),
            'attempts' => 0,
        ]);

        $this->markPhoneOtpSent();

        return $verificationCode;
    }

    /**
     * The OTP sent to the phone. Demo build uses a fixed code because no SMS
     * gateway is connected yet — the Android OTP screen shows this hint.
     */
    public function phoneOtpCode(): string
    {
        return (string) config('auth_flow.phone_otp_fixed', '123456');
    }

    public function phoneOtpCooldownSeconds(): int
    {
        return (int) config('auth_flow.otp_resend_cooldown_seconds', 60);
    }

    private function assertPhoneOtpResendAllowed(): void
    {
        $cooldown = $this->phoneOtpCooldownSeconds();

        if ($cooldown <= 0) {
            return;
        }

        if (Cache::has($this->phoneOtpCacheKey())) {
            throw ValidationException::withMessages([
                'code' => [__('api.verification_code_resend_wait', ['seconds' => $cooldown])],
            ]);
        }
    }

    private function markPhoneOtpSent(): void
    {
        $cooldown = $this->phoneOtpCooldownSeconds();

        if ($cooldown <= 0) {
            return;
        }

        Cache::put($this->phoneOtpCacheKey(), true, now()->addSeconds($cooldown));
    }

    private function phoneOtpCacheKey(): string
    {
        return sprintf('phone_otp_resend:%s:%s', $this->getMorphClass(), $this->getKey());
    }
}
