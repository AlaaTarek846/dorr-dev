<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Rides\Models\Concerns\LocalizedJson;

/** A weekly / seasonal challenge (R-151, R-155): a target inside its dates, points when reached. */
class LoyaltyChallenge extends Model
{
    use LocalizedJson;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['title' => 'array', 'description' => 'array', 'target' => 'integer', 'reward_points' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'status' => 'boolean'];
    }

    public function isLive(): bool
    {
        return $this->status && $this->starts_at->isPast() && $this->ends_at->isFuture();
    }
}
