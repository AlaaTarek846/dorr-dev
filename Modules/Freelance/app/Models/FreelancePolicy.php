<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Freelance\Services\Policy\FreelancePolicies;

/**
 * The keys one scope overrides — a country, or a country + specialty (FL-008). `data` holds only the
 * keys this scope changes; FreelancePolicies merges the scopes, most specific last.
 */
class FreelancePolicy extends Model
{
    protected $fillable = ['country_id', 'specialty_id', 'data', 'status', 'updated_by_admin_id'];

    protected static function booted(): void
    {
        static::saved(fn () => FreelancePolicies::forget());
        static::deleted(fn () => FreelancePolicies::forget());
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'status' => 'boolean',
            'country_id' => 'integer',
            'specialty_id' => 'integer',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
