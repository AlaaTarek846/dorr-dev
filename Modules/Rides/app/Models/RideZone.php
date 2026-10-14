<?php

namespace Modules\Rides\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Rides\Enums\ZoneType;
use Modules\Rides\Support\Geo;

/**
 * A polygon with a meaning (service area, blocked, airport, surcharge…). The bounding box is kept
 * in sync from the polygon so a lookup can filter in SQL first (docs/rides-structure.md §0.3).
 */
class RideZone extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['country_id', 'city_id', 'type', 'polygon', 'config', 'priority', 'status'];

    protected static function booted(): void
    {
        static::saving(function (RideZone $zone): void {
            [$zone->min_lat, $zone->max_lat, $zone->min_lng, $zone->max_lng] = Geo::boundingBox($zone->polygon ?? []);
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ZoneType::class,
            'polygon' => 'array',
            'config' => 'array',
            'city_id' => 'integer',
            'priority' => 'integer',
            'status' => 'boolean',
            'min_lat' => 'float',
            'max_lat' => 'float',
            'min_lng' => 'float',
            'max_lng' => 'float',
        ];
    }

    public function contains(float $lat, float $lng): bool
    {
        return Geo::inPolygon($lat, $lng, $this->polygon ?? []);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(RideZoneTranslation::class);
    }

    protected function translationModel(): string
    {
        return RideZoneTranslation::class;
    }
}
