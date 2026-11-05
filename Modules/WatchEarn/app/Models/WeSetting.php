<?php

namespace Modules\WatchEarn\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Country;

/**
 * Watch & Earn for one country (WE-001): the price of a view and how it is split, the viewing mode, media limits,
 * the ad wallet minimum, warnings, reports and reward waits. Nothing of this is hard-coded anywhere else.
 */
class WeSetting extends Model
{
    public const UNCLAIMED_PLATFORM = 'platform';

    public const UNCLAIMED_NOT_CHARGED = 'not_charged';

    protected $table = 'we_settings';

    protected $fillable = [
        'country_id', 'enabled', 'price_per_view_minor', 'platform_share_minor', 'view_reward_minor', 'interaction_reward_minor',
        'unclaimed_interaction', 'viewing_mode', 'image_seconds', 'video_max_seconds', 'video_max_mb', 'min_ad_wallet_minor',
        'low_balance_percent', 'report_threshold', 'interaction_window_minutes', 'view_reward_wait_hours', 'interaction_reward_wait_hours',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'price_per_view_minor' => 'integer', 'platform_share_minor' => 'integer', 'view_reward_minor' => 'integer',
            'interaction_reward_minor' => 'integer', 'image_seconds' => 'integer', 'video_max_seconds' => 'integer', 'video_max_mb' => 'integer',
            'min_ad_wallet_minor' => 'integer', 'low_balance_percent' => 'integer', 'report_threshold' => 'integer',
            'interaction_window_minutes' => 'integer', 'view_reward_wait_hours' => 'integer', 'interaction_reward_wait_hours' => 'integer',
        ];
    }

    /** The country's row, or an unsaved one with the defaults (off) when the admin never set it. */
    public static function forCountry(int $countryId): self
    {
        return self::query()->where('country_id', $countryId)->first()
            ?? (new self(['country_id' => $countryId]))->forceFill(self::defaults());
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => false, 'price_per_view_minor' => 0, 'platform_share_minor' => 0, 'view_reward_minor' => 0, 'interaction_reward_minor' => 0,
            'unclaimed_interaction' => self::UNCLAIMED_PLATFORM, 'viewing_mode' => 'auto', 'image_seconds' => 20, 'video_max_seconds' => 60,
            'video_max_mb' => 60, 'min_ad_wallet_minor' => 0, 'low_balance_percent' => 20, 'report_threshold' => 10,
            'interaction_window_minutes' => 10, 'view_reward_wait_hours' => 0, 'interaction_reward_wait_hours' => 0,
        ];
    }

    /** Ready to take ads: on, priced, and the split adds up to the price. */
    public function usable(): bool
    {
        return $this->enabled && $this->price_per_view_minor > 0
            && $this->platform_share_minor + $this->view_reward_minor + $this->interaction_reward_minor === $this->price_per_view_minor;
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
