<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A rider's DORR points (R-147): the balance, all ever earned, and the tier now. */
class LoyaltyAccount extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'lifetime' => 'integer'];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class);
    }
}
