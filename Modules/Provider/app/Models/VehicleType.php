<?php

namespace Modules\Provider\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Country;
use App\Models\ServiceCategory;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;

/** A kind of provider vehicle the admin defines (quarter truck, half truck, jumbo, crane truck…), per country and service. */
class VehicleType extends Model implements HasMedia
{
    use HasMediaTrait, HasTranslations, SoftDeletes;

    protected $table = 'provider_vehicle_types';

    protected $fillable = ['code', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(VehicleTypeTranslation::class, 'provider_vehicle_type_id');
    }

    protected function translationModel(): string
    {
        return VehicleTypeTranslation::class;
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'provider_vehicle_type_countries', 'vehicle_type_id', 'country_id');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'provider_vehicle_type_services', 'vehicle_type_id', 'service_category_id');
    }
}
