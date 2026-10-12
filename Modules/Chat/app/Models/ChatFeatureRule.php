<?php

namespace Modules\Chat\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Services\ChatFeatures;

/**
 * A chat feature on or off for a country, or for one of its cities (see ChatFeatures).
 */
class ChatFeatureRule extends Model
{
    public const FEATURES = ['calls', 'voice_rooms', 'live', 'stories', 'ai'];

    protected $fillable = ['feature', 'country_id', 'city_id', 'enabled', 'note'];

    /** Every change reaches the next request (the rules are cached). */
    protected static function booted(): void
    {
        static::saved(fn () => ChatFeatures::forget());
        static::deleted(fn () => ChatFeatures::forget());
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
