<?php

namespace Modules\Discover\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** The admin's Discover switches (spec 182) — one row, cached. */
class DiscoverSetting extends Model
{
    protected $fillable = ['enabled', 'enabled_countries', 'submissions_enabled', 'auto_publish_verified', 'alerts_enabled', 'max_alerts_per_week', 'room_close_hours', 'ai_enabled'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'enabled_countries' => 'array', 'submissions_enabled' => 'boolean', 'auto_publish_verified' => 'boolean',
            'alerts_enabled' => 'boolean', 'max_alerts_per_week' => 'integer', 'room_close_hours' => 'integer', 'ai_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return Cache::rememberForever('discover.settings', fn () => self::query()->firstOrCreate([])->refresh());
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('discover.settings'));
    }

    /** On for this country (no list = everywhere). */
    public function onIn(?int $countryId): bool
    {
        return $this->enabled && (empty($this->enabled_countries) || ($countryId !== null && in_array($countryId, array_map('intval', $this->enabled_countries), true)));
    }
}
