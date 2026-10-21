<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** A badge a rider earned (R-150). */
class LoyaltyUserBadge extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['earned_at' => 'datetime'];
    }
}
