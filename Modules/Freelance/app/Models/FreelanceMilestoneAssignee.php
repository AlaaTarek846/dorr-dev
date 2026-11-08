<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FS-206b: a person of the team put on one part (milestone) of a team's project.
 *
 * @property int $id
 * @property int $milestone_id
 * @property int $freelancer_id
 * @property ?int $assigned_by_freelancer_id
 */
class FreelanceMilestoneAssignee extends Model
{
    protected $table = 'freelance_milestone_assignees';

    protected $fillable = ['milestone_id', 'freelancer_id', 'assigned_by_freelancer_id'];

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(FreelanceMilestone::class, 'milestone_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class, 'freelancer_id');
    }
}
