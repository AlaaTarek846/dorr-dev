<?php

namespace Modules\Rides\Models;

use Illuminate\Database\Eloquent\Model;

/** How far a rider is in a challenge (R-151). */
class LoyaltyChallengeProgress extends Model
{
    protected $table = 'loyalty_challenge_progress';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['progress' => 'integer', 'failed' => 'boolean', 'completed_at' => 'datetime'];
    }
}
