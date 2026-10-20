<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The one rides settings row (admin → Rides → Settings): the map provider — Google or Mapbox, one of
 * them (O-01, O-02) — with its key (encrypted), and the straight-line estimate's numbers. The keys from
 * the admin win over GOOGLE_MAPS_SERVER_KEY / MAPBOX_TOKEN in .env.
 */
class RideSetting extends Model
{
    private const CACHE = 'rides:settings';

    public const PROVIDERS = ['google', 'mapbox'];

    protected $fillable = ['google_maps_key', 'map_provider', 'mapbox_token', 'mapbox_style', 'fallback_speed_kmh', 'fallback_road_factor_bp', 'geocode_cache_days'];

    protected $hidden = ['google_maps_key', 'mapbox_token'];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE));
    }

    protected function casts(): array
    {
        return [
            'google_maps_key' => 'encrypted',
            'mapbox_token' => 'encrypted',
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

    /** `google` or `mapbox` — the one the server and both apps use. */
    public function mapProvider(): string
    {
        return in_array($this->map_provider, self::PROVIDERS, true) ? $this->map_provider : 'google';
    }

    public function mapboxToken(): string
    {
        return (string) ($this->mapbox_token ?: config('rides.mapbox.token'));
    }
}
