<?php

namespace Modules\Rides\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Rides\Services\Policy\RidePolicies;

/**
 * The numbers one scope overrides — country, city, zone and/or category (docs/rides-structure.md
 * §2.4). `data` holds only the keys this scope changes; RidePolicies merges the scopes, most
 * specific last. The scope's uniqueness is enforced by RidePolicies::save (the unique index
 * can't see NULL columns).
 */
class RidePolicy extends Model
{
    protected $fillable = ['country_id', 'city_id', 'zone_id', 'category_id', 'data', 'status', 'updated_by_admin_id'];

    protected static function booted(): void
    {
        static::saved(fn () => RidePolicies::forget());
        static::deleted(fn () => RidePolicies::forget());
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'status' => 'boolean',
            'country_id' => 'integer',
            'city_id' => 'integer',
            'zone_id' => 'integer',
            'category_id' => 'integer',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(RideZone::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RideCategory::class);
    }
}
