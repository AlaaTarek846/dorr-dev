<?php

namespace Modules\Rides\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Rides\Services\Features\RideFeatures;

/** A ride feature on or off for a country, or for one of its cities (see RideFeatures). */
class RideFeatureRule extends Model
{
    protected $fillable = ['feature', 'country_id', 'city_id', 'enabled', 'note'];

    /** Every change reaches the next request (the rules are cached). */
    protected static function booted(): void
    {
        static::saved(fn () => RideFeatures::forget());
        static::deleted(fn () => RideFeatures::forget());
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'country_id' => 'integer', 'city_id' => 'integer'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
