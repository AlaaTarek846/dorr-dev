<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of an account's security log (spec item 28). Kept for a year (`model:prune`, daily).
 */
class SecurityEvent extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    // Sign-in
    public const LOGIN = 'login';

    public const LOGIN_NEW_DEVICE = 'login_new_device';

    public const LOGIN_FAILED = 'login_failed';

    public const OTP_REQUESTED = 'otp_requested';

    public const OTP_FAILED = 'otp_failed';

    public const OTP_LOCKED = 'otp_locked';

    public const LOGOUT = 'logout';

    // Devices
    public const DEVICE_SIGNED_OUT = 'device_signed_out';

    public const OTHER_DEVICES_SIGNED_OUT = 'other_devices_signed_out';

    public const SESSION_LIMIT = 'session_limit';

    public const ADMIN_SIGNED_OUT = 'admin_signed_out';

    // Account / wallet
    public const PHONE_CHANGED = 'phone_changed';

    public const PIN_FAILED = 'pin_failed';

    public const PIN_LOCKED = 'pin_locked';

    public const PIN_FROZEN = 'pin_frozen';

    public const ACCOUNT_RESTORED = 'account_restored';

    /** The kinds that count as a warning in the app and the dashboard. */
    public const WARNINGS = [
        self::LOGIN_NEW_DEVICE, self::LOGIN_FAILED, self::OTP_FAILED, self::OTP_LOCKED,
        self::SESSION_LIMIT, self::ADMIN_SIGNED_OUT, self::PIN_FAILED, self::PIN_LOCKED, self::PIN_FROZEN,
    ];

    public const ALL = [
        self::LOGIN, self::LOGIN_NEW_DEVICE, self::LOGIN_FAILED, self::OTP_REQUESTED, self::OTP_FAILED, self::OTP_LOCKED,
        self::LOGOUT, self::DEVICE_SIGNED_OUT, self::OTHER_DEVICES_SIGNED_OUT, self::SESSION_LIMIT, self::ADMIN_SIGNED_OUT,
        self::PHONE_CHANGED, self::PIN_FAILED, self::PIN_LOCKED, self::PIN_FROZEN, self::ACCOUNT_RESTORED,
    ];

    protected $fillable = [
        'owner_type', 'owner_id', 'type', 'account_session_id', 'ip', 'country_code', 'device_id', 'device_name',
        'platform', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AccountSession::class, 'account_session_id');
    }

    public function scopeOf(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey());
    }

    public function isWarning(): bool
    {
        return in_array($this->type, self::WARNINGS, true);
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('auth_flow.security_log_days', 365)));
    }
}
