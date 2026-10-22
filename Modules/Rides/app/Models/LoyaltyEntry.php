<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** One movement of DORR points — append-only (R-146, R-153). */
class LoyaltyEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['points' => 'integer', 'balance_after' => 'integer', 'note' => 'array', 'created_at' => 'datetime'];
    }
}
