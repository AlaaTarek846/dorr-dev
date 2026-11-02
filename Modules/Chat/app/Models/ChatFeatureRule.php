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
    /** The last two are account verification's tracks (docs/verification_tasks.md AV-002). */
    public const FEATURES = ['calls', 'voice_rooms', 'live', 'stories', 'ai', 'verification_paid', 'verification_celebrity', 'education_groups', 'work_groups'];

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
