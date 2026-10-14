<?php

namespace Modules\Rides\Models;

use App\Models\Concerns\HasTranslations;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Driver\Models\DriverDocumentType;
use Modules\Rides\Enums\PricingStrategy;
use Spatie\MediaLibrary\HasMedia;

/**
 * One of the 11 ride categories (economy, with-me, ladies, VIP, offers…). Whether it is on in a
 * country or city is a feature switch (`category.{slug}`), not this row's status alone.
 */
class RideCategory extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations, SoftDeletes;

    protected $fillable = ['slug', 'pricing_strategy', 'capacity_min', 'capacity_max', 'eligibility', 'flags', 'color', 'status', 'sort_order'];

    protected function casts(): array
    {
        return [
            'pricing_strategy' => PricingStrategy::class,
            'capacity_min' => 'integer',
            'capacity_max' => 'integer',
            'eligibility' => 'array',
            'flags' => 'array',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function flag(string $key, bool $default = false): bool
    {
        return (bool) ($this->flags[$key] ?? $default);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(RideCategoryTranslation::class);
    }

    /** Documents this category needs on top of the country's usual ones (a bus or boat licence…). */
    public function documentTypes(): BelongsToMany
    {
        return $this->belongsToMany(DriverDocumentType::class, 'ride_category_document_types', 'category_id', 'document_type_id');
    }

    protected function translationModel(): string
    {
        return RideCategoryTranslation::class;
    }
}
