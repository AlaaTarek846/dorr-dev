<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A period the freelancer marked as time off (FL-106): no new offers while it is running. */
class FreelancerTimeOff extends Model
{
    protected $table = 'freelancer_time_off';

    protected $fillable = ['freelancer_id', 'starts_on', 'ends_on', 'reason'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }
}
