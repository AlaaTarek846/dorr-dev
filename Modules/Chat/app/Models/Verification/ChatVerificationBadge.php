<?php

namespace Modules\Chat\Models\Verification;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Enums\VerificationBadgeStatus;
use Modules\Chat\Support\VerifiedAccounts;
use Modules\User\Models\User;

/**
 * An account's verification badge (AV-020). Changed only through VerificationBadgeService; every save
 * forgets the cached list of verified accounts (VerifiedAccounts), so the ✔ follows at once everywhere.
 */
class ChatVerificationBadge extends Model
{
    protected $fillable = ['user_id', 'status', 'type', 'request_id', 'active_from', 'expires_at', 'suspended_from', 'reason'];

    protected function casts(): array
    {
        return [
            'status' => VerificationBadgeStatus::class,
            'suspended_from' => VerificationBadgeStatus::class,
            'user_id' => 'integer',
            'request_id' => 'integer',
            'active_from' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Now, and again once the transaction commits (a list read in between must not keep the old answer).
        $forget = function () {
            VerifiedAccounts::forget();
            DB::afterCommit(fn () => VerifiedAccounts::forget());
        };
        static::saved($forget);
        static::deleted($forget);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChatVerificationBadgeEvent::class, 'badge_id')->orderBy('id');
    }

    /** The ✔ shows now: temporary or active, and not past its end. */
    public function shows(): bool
    {
        return $this->status->shows() && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
