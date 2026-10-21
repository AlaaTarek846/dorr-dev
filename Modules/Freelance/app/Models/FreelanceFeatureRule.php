<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Freelance\Services\Features\FreelanceFeatures;

/** A freelance feature on or off for a country (see FreelanceFeatures). */
class FreelanceFeatureRule extends Model
{
    protected $fillable = ['feature', 'country_id', 'enabled', 'note'];

    /** Every change reaches the next request (the rules are cached). */
    protected static function booted(): void
    {
        static::saved(fn () => FreelanceFeatures::forget());
        static::deleted(fn () => FreelanceFeatures::forget());
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'country_id' => 'integer'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
