<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The one rides settings row (admin → Rides → Settings): the Google Maps key (encrypted) and the
 * free estimate's numbers. The key from the admin wins over GOOGLE_MAPS_SERVER_KEY in .env.
 */
class RideSetting extends Model
{
    private const CACHE = 'rides:settings';

    protected $fillable = ['google_maps_key', 'fallback_speed_kmh', 'fallback_road_factor_bp', 'geocode_cache_days'];

    protected $hidden = ['google_maps_key'];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE));
    }

    protected function casts(): array
    {
        return [
            'google_maps_key' => 'encrypted',
            'fallback_speed_kmh' => 'integer',
            'fallback_road_factor_bp' => 'integer',
            'geocode_cache_days' => 'integer',
        ];
    }

    public static function current(): self
    {
        $id = Cache::remember(self::CACHE, 300, fn () => self::query()->value('id'));

        // A fresh row is re-read so the column defaults (speed, road factor…) are filled in.
        return ($id ? self::query()->find($id) : null) ?? self::query()->create([])->refresh();
    }

    public function googleKey(): string
    {
        return (string) ($this->google_maps_key ?: config('rides.google.key'));
    }
}
