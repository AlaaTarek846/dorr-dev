<?php

namespace App\Services\Auth;

use App\Enums\VerificationType;
use App\Mail\VerificationCodeMail;
use App\Models\VerificationCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VerificationCodeService
{
    public function send(Model $authenticatable, VerificationType $type, string $destination): VerificationCode
    {
        $this->assertResendAllowed($authenticatable, $type);

        $authenticatable->verificationCodes()
            ->where('type', $type->value)
            ->whereNull('verified_at')
            ->delete();

        $code = $this->generateCode();

        $verificationCode = $authenticatable->verificationCodes()->create([
            'type' => $type->value,
            'code' => $code,
            'expires_at' => now()->addMinutes((int) config('auth_flow.otp_expiry_minutes', 10)),
            'attempts' => 0,
        ]);

        if ($type === VerificationType::Email) {
            Mail::to($destination)->send(new VerificationCodeMail($code, $authenticatable));
        }

        $this->markResent($authenticatable, $type);

        return $verificationCode;
    }

    public function verify(Model $authenticatable, VerificationType $type, string $code): VerificationCode
    {
        /** @var VerificationCode|null $record */
        $record = $authenticatable->verificationCodes()
            ->where('type', $type->value)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'code' => [__('api.verification_code_invalid')],
            ]);
        }

        if ($record->isExpired()) {
            throw ValidationException::withMessages([
                'code' => [__('api.verification_code_expired')],
            ]);
        }

        $maxAttempts = (int) config('auth_flow.otp_max_attempts', 5);

        if ($record->attempts >= $maxAttempts) {
            throw ValidationException::withMessages([
                'code' => [__('api.verification_code_max_attempts')],
            ]);
        }

        if (! hash_equals($record->code, $code)) {
            $record->increment('attempts');

            throw ValidationException::withMessages([
                'code' => [__('api.verification_code_invalid')],
            ]);
        }

        $record->update(['verified_at' => now()]);

        return $record->fresh();
    }

    public function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, min(2, mb_strlen($local)));

        return $visible.str_repeat('*', max(1, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }

    private function generateCode(): string
    {
        $length = max(4, (int) config('auth_flow.otp_length', 6));
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function assertResendAllowed(Model $authenticatable, VerificationType $type): void
    {
        $cooldown = (int) config('auth_flow.otp_resend_cooldown_seconds', 60);

        if ($cooldown <= 0) {
            return;
        }

        $key = $this->resendCacheKey($authenticatable, $type);

        if (Cache::has($key)) {
            throw ValidationException::withMessages([
                'code' => [__('api.verification_code_resend_wait', ['seconds' => $cooldown])],
            ]);
        }
    }

    private function markResent(Model $authenticatable, VerificationType $type): void
    {
        $cooldown = (int) config('auth_flow.otp_resend_cooldown_seconds', 60);

        if ($cooldown <= 0) {
            return;
        }

        Cache::put(
            $this->resendCacheKey($authenticatable, $type),
            true,
            now()->addSeconds($cooldown),
        );
    }

    private function resendCacheKey(Model $authenticatable, VerificationType $type): string
    {
        return sprintf(
            'verification_resend:%s:%s:%s',
            $authenticatable->getMorphClass(),
            $authenticatable->getKey(),
            $type->value,
        );
    }
}
