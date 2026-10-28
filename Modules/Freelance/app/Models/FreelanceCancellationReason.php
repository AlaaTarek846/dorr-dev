<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FL-607: one stated reason for calling off a project or a part of it. */
class FreelanceCancellationReason extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'agreement_id', 'milestone_id', 'actor_type', 'actor_id', 'reason_code', 'responsibility', 'note'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }
}
