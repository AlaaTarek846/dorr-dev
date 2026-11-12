<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The staff stop a member from posting, commenting and reacting in the community until a date (FL-L12); they can still read. */
class FreelanceCommunityMute extends Model
{
    protected $table = 'freelance_community_mutes';

    protected $fillable = ['freelancer_id', 'ends_at', 'reason', 'admin_id', 'lifted_at'];

    protected function casts(): array
    {
        return ['ends_at' => 'datetime', 'lifted_at' => 'datetime'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }
}
