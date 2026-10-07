<?php

namespace Modules\Chat\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

/**
 * An occasion in the catalog (DORR Moments, spec 157–159). See the migration for its fields.
 */
class ChatMoment extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations;

    public const CARD = 'card';

    public const KINDS = ['religious', 'national', 'international', 'social', 'cultural', 'seasonal'];

    public const RULES = ['gregorian', 'hijri', 'manual'];

    public const ANIMATIONS = ['confetti', 'lanterns', 'fireworks', 'hearts', 'stars', 'balloons', 'flags', 'snow', 'petals', 'sparkles'];

    protected $fillable = [
        'key', 'kind', 'date_rule', 'month', 'day', 'duration_days', 'show_before_days', 'show_after_days',
        'countries', 'default_on', 'theme', 'primary_color', 'secondary_color', 'emoji', 'animation', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'default_on' => 'boolean',
            'status' => 'boolean',
            'month' => 'integer',
            'day' => 'integer',
            'duration_days' => 'integer',
            'show_before_days' => 'integer',
            'show_after_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ChatMomentTranslation::class);
    }

    protected function translationModel(): string
    {
        return ChatMomentTranslation::class;
    }

    public function dates(): HasMany
    {
        return $this->hasMany(ChatMomentDate::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /** Whether it's an occasion in this country (no list = everywhere). */
    public function isIn(?string $countryCode): bool
    {
        return empty($this->countries) || ($countryCode !== null && in_array(strtoupper($countryCode), array_map('strtoupper', $this->countries), true));
    }

    public function cardUrl(): ?string
    {
        return $this->getSingleMediaUrl(self::CARD) ?: null;
    }
}
