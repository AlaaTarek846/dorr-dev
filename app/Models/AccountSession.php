<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One signed-in device of an account: its own Sanctum token, what the device said it is (id, name, platform, app
 * version) and where it signed in from. Signing it out deletes the token (its next request is a 401) and keeps the
 * row, marked revoked, for the security log.
 */
class AccountSession extends Model
{
    public const REASON_LOGOUT = 'logout';

    public const REASON_USER = 'user';

    public const REASON_OTHERS = 'others';

    public const REASON_LIMIT = 'limit';

    public const REASON_ADMIN = 'admin';

    public const REASON_REPLACED = 'replaced';

    protected $fillable = [
        'owner_type', 'owner_id', 'token_id', 'device_id', 'device_name', 'platform', 'app_version',
        'ip', 'country_code', 'user_agent', 'revoked_at', 'revoked_reason',
    ];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'token_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeOf(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey());
    }
}
