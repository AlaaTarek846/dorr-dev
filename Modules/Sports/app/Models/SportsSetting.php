<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** The admin's Sports switches — one row, cached. */
class SportsSetting extends Model
{
    public const TIERS = ['big', 'normal', 'minor'];

    protected $fillable = ['enabled', 'enabled_countries', 'tier_seconds', 'reserve_percent', 'daily_limit', 'predictions_enabled', 'prizes_countries', 'odds_countries'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'enabled_countries' => 'array', 'tier_seconds' => 'array', 'reserve_percent' => 'integer', 'daily_limit' => 'integer', 'predictions_enabled' => 'boolean', 'prizes_countries' => 'array', 'odds_countries' => 'array'];
    }

    public static function current(): self
    {
        return Cache::rememberForever('sports.settings', fn () => self::query()->firstOrCreate([])->refresh());
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('sports.settings'));
    }

    public function onIn(?int $countryId): bool
    {
        return $this->enabled && (empty($this->enabled_countries) || ($countryId !== null && in_array($countryId, array_map('intval', $this->enabled_countries), true)));
    }

    /** Odds are information only, and only where the admin turned them on (null = nowhere). */
    public function oddsIn(?int $countryId): bool
    {
        return $countryId !== null && in_array($countryId, array_map('intval', (array) ($this->odds_countries ?? [])), true);
    }

    /** The live update interval for a tier, before the budget governor stretches it. */
    public function secondsFor(string $tier): int
    {
        $own = (array) ($this->tier_seconds ?? []);

        return max((int) config('sports.min_seconds', 30), (int) ($own[$tier] ?? config("sports.tier_seconds.{$tier}", 180)));
    }
}
