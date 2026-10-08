<?php

namespace Modules\Discover\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A city events happen in, with its time zone (admin catalog). */
class DiscoverCity extends Model
{
    use HasTranslations;

    protected $fillable = ['country_id', 'timezone', 'lat', 'lng', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'sort_order' => 'integer', 'lat' => 'float', 'lng' => 'float'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(DiscoverCityTranslation::class);
    }

    protected function translationModel(): string
    {
        return DiscoverCityTranslation::class;
    }
}
