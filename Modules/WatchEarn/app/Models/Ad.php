<?php

namespace Modules\WatchEarn\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Modules\User\Models\User;
use Modules\WatchEarn\Enums\AdStatus;

/**
 * One ad (WE-020): an image or a video, its audience, dates, the price it was sent at, its budget by bucket and the
 * counters the stats page reads.
 */
class Ad extends Model
{
    public const DISK = 'public';

    protected $table = 'we_ads';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AdStatus::class,
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime',
            'paused_at' => 'datetime', 'suspended_at' => 'datetime', 'closed_at' => 'datetime', 'low_balance_notified_at' => 'datetime',
            'media_size' => 'integer', 'media_seconds' => 'integer', 'media_version' => 'integer',
            'price_per_view_minor' => 'integer', 'platform_share_minor' => 'integer', 'view_reward_minor' => 'integer', 'interaction_reward_minor' => 'integer',
            'budget_minor' => 'integer', 'spent_minor' => 'integer',
            'reserved_reward_minor' => 'integer', 'reserved_spend_only_minor' => 'integer', 'reserved_withdrawable_minor' => 'integer',
            'impressions_count' => 'integer', 'completed_count' => 'integer', 'interactions_count' => 'integer',
            'comments_count' => 'integer', 'reactions_count' => 'integer', 'reports_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function reservedMinor(): int
    {
        return $this->reserved_reward_minor + $this->reserved_spend_only_minor + $this->reserved_withdrawable_minor;
    }

    /** Views the rest of the budget still pays for. */
    public function viewsLeft(): int
    {
        return $this->price_per_view_minor > 0 ? intdiv($this->reservedMinor(), $this->price_per_view_minor) : 0;
    }

    /** Shown to viewers right now. */
    public function live(): bool
    {
        return $this->status === AdStatus::Active && $this->starts_at->isPast() && $this->ends_at->isFuture()
            && $this->reservedMinor() >= $this->price_per_view_minor;
    }

    public function mediaUrl(): ?string
    {
        return $this->media_path ? Storage::disk(self::DISK)->url($this->media_path) : null;
    }

    public function thumbUrl(): ?string
    {
        return $this->thumb_path ? Storage::disk(self::DISK)->url($this->thumb_path) : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adWallet(): BelongsTo
    {
        return $this->belongsTo(AdWallet::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return HasMany<AdCity> */
    public function cities(): HasMany
    {
        return $this->hasMany(AdCity::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(AdView::class);
    }
}
