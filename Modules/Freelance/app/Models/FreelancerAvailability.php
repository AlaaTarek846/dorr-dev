<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How a freelancer can be reached (FL-106): the pause, the guide price and the weekly hours. */
class FreelancerAvailability extends Model
{
    protected $table = 'freelancer_availability';

    protected $fillable = ['freelancer_id', 'accepting_requests', 'paused_until', 'pause_reason', 'rate_type', 'rate_min_minor', 'rate_max_minor', 'weekly_hours'];

    protected function casts(): array
    {
        return [
            'accepting_requests' => 'boolean',
            'paused_until' => 'date',
            'rate_min_minor' => 'integer',
            'rate_max_minor' => 'integer',
            'weekly_hours' => 'array',
        ];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }
}
