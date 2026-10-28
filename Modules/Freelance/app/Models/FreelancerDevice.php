<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A device a freelancer is signed in on — one Sanctum token each. */
class FreelancerDevice extends Model
{
    protected $fillable = ['freelancer_id', 'token_id', 'device_id', 'device_name', 'platform', 'ip', 'user_agent', 'last_active_at'];

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime', 'token_id' => 'integer', 'freelancer_id' => 'integer'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class);
    }
}
