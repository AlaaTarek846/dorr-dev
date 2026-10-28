<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing that happened to a project (FL-202): who, from which status to which, when. Written once, never changed. */
class FreelanceProjectEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'type', 'from_status', 'to_status', 'actor_type', 'actor_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }
}
