<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A captain's rank now, their last numbers, and a lower rank pending its grace (D-253). */
class DriverRankState extends Model
{
    protected $primaryKey = 'driver_id';

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'computed_at' => 'datetime', 'downgrade_due_at' => 'datetime'];
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(DriverRank::class);
    }

    public function fallingTo(): BelongsTo
    {
        return $this->belongsTo(DriverRank::class, 'falling_to_id');
    }
}
