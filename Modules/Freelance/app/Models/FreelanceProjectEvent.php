<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing that happened to a project (FL-202): who, from which status to which, when. Written once, never changed. */
class FreelanceProjectEvent extends Model
{
    public const UPDATED_AT = null;

    /** FS-150c: who an event is for - `both` the client and the freelancer, one side only, or `team` (the freelancer's team; never the client). */
    public const AUDIENCES = ['both', 'client', 'freelancer', 'team'];

    protected $fillable = ['project_id', 'type', 'from_status', 'to_status', 'actor_type', 'actor_id', 'payload', 'acted_by_user_id', 'acted_by_freelancer_id', 'audience'];

    /** Only the events this side may see (staff read every row, so they do not use it). @param  'client'|'freelancer'  $side */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, string $side): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn('audience', ['both', $side]);
    }

    /** @param  'client'|'freelancer'  $side */
    public function isVisibleTo(string $side): bool
    {
        return in_array($this->audience ?? 'both', ['both', $side], true);
    }

    protected static function booted(): void
    {
        // FS-104: a member of the owner's team took this step for him.
        static::creating(function (self $e): void {
            $e->acted_by_user_id ??= \Modules\Freelance\Services\Team\ClientTeamContext::actorId();
            // FS-206a: somebody of the freelancers' team took it for the Owner who signed.
            $e->acted_by_freelancer_id ??= \Modules\Freelance\Services\FreelancerTeam\FreelancerTeamContext::actorId();
        });
    }


    /** FS-150a: the Workroom's steps (`workroom.task.created`…) are on the record but not part of the project's own history the apps list. */
    public function isWorkroom(): bool
    {
        return str_starts_with((string) $this->type, 'workroom.');
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(FreelanceProject::class, 'project_id');
    }
}
