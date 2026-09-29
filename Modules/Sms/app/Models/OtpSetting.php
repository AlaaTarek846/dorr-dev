<?php

namespace Modules\Sms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class OtpSetting extends Model
{
    private const CACHE_KEY = 'otp.settings';

    protected $fillable = [
        'enabled',
        'preferred_channel',
        'fallback_channel',
        'otp_length',
        'expiration_minutes',
        'resend_cooldown_seconds',
        'max_attempts',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'otp_length' => 'integer',
            'expiration_minutes' => 'integer',
            'resend_cooldown_seconds' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleting(fn () => Cache::forget(self::CACHE_KEY));
    }

    public static function current(): self
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->firstOrCreate([], [
            'enabled' => true,
            'preferred_channel' => 'whatsapp',
            'fallback_channel' => 'sms',
            'otp_length' => 6,
            'expiration_minutes' => 5,
            'resend_cooldown_seconds' => 30,
            'max_attempts' => 3,
        ]));
    }
}
