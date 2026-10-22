<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Rides\Models\Concerns\LocalizedJson;

/** A DORR points tier (R-148, R-154): reached by the points of the last window; multiplies what each ride earns. */
class LoyaltyTier extends Model
{
    use LocalizedJson;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['name' => 'array', 'benefits' => 'array', 'min_points' => 'integer', 'multiplier_bp' => 'integer', 'status' => 'boolean'];
    }

    /** @return list<string> */
    public function benefitList(): array
    {
        $b = (array) ($this->benefits ?? []);

        return array_values((array) ($b[app()->getLocale()] ?? $b['ar'] ?? $b['en'] ?? []));
    }
}
