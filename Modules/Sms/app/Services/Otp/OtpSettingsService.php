<?php

namespace Modules\Sms\Services\Otp;

use Modules\Sms\Models\OtpSetting;

class OtpSettingsService
{
    public function get(): OtpSetting
    {
        return OtpSetting::current();
    }

    public function update(array $data): OtpSetting
    {
        $setting = OtpSetting::current();
        $setting->update($data);

        return $setting->fresh();
    }

    public function isEnabled(): bool
    {
        return $this->get()->enabled;
    }

    public function preferredChannel(): string
    {
        return $this->get()->preferred_channel;
    }

    public function fallbackChannel(): string
    {
        return $this->get()->fallback_channel;
    }

    public function otpLength(): int
    {
        return $this->get()->otp_length;
    }

    public function expirationMinutes(): int
    {
        return $this->get()->expiration_minutes;
    }

    public function resendCooldownSeconds(): int
    {
        return $this->get()->resend_cooldown_seconds;
    }

    public function maxAttempts(): int
    {
        return $this->get()->max_attempts;
    }
}
