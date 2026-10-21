<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Rides\Models\Concerns\LocalizedJson;

/** Something to spend DORR points on (R-152): wallet credit or a coupon, in one country. */
class LoyaltyReward extends Model
{
    use LocalizedJson;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['title' => 'array', 'description' => 'array', 'cost_points' => 'integer', 'value_minor' => 'integer', 'coupon_percent' => 'integer', 'coupon_max_minor' => 'integer', 'coupon_days' => 'integer', 'stock' => 'integer', 'per_user_limit' => 'integer', 'status' => 'boolean'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Country::class);
    }

    public function minTier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'min_tier_id');
    }
}
