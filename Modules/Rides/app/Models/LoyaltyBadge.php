<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Rides\Models\Concerns\LocalizedJson;

/** A badge (R-150): earned once, when its rule reaches its threshold. */
class LoyaltyBadge extends Model
{
    use LocalizedJson;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['name' => 'array', 'description' => 'array', 'threshold' => 'integer', 'reward_points' => 'integer', 'status' => 'boolean'];
    }
}
