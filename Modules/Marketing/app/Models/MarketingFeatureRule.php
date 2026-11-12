<?php

namespace Modules\Marketing\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Marketing\Services\Features\MarketingFeatures;

/** A marketing feature on or off for a country (see MarketingFeatures). */
class MarketingFeatureRule extends Model
{
    protected $fillable = ['feature', 'country_id', 'enabled', 'note'];

    /** Every change reaches the next request (the rules are cached). */
    protected static function booted(): void
    {
        static::saved(fn () => MarketingFeatures::forget());
        static::deleted(fn () => MarketingFeatures::forget());
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
