<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A freelancer's loyalty program (FL-L09): the steps of discount a returning client earns with them, by finished works together.
 * `tiers` is `[{after, discount_bp}]`, the first step first, each step with a bigger `after` and a bigger discount than the one before.
 */
class FreelanceLoyaltyProgram extends Model
{
    protected $fillable = ['freelancer_id', 'enabled', 'tiers'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'tiers' => 'array'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }
}
