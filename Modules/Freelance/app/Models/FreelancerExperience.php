<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One job on a freelancer's work history (FL-104). A current job has no end date. */
class FreelancerExperience extends Model
{
    protected $fillable = ['freelancer_id', 'title', 'company', 'started_on', 'ended_on', 'is_current', 'description'];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'ended_on' => 'date', 'is_current' => 'boolean'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }
}
