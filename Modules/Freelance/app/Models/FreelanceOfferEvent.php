<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;

/** One thing that happened to an offer (FL-301): who, from which status to which, when. Written once, never changed. */
class FreelanceOfferEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['offer_id', 'type', 'from_status', 'to_status', 'actor_type', 'actor_id', 'payload', 'acted_by_user_id', 'acted_by_freelancer_id'];

    protected static function booted(): void
    {
        // FS-104: a member of the owner's team took this step for him.
        static::creating(function (self $e): void {
            $e->acted_by_user_id ??= \Modules\Freelance\Services\Team\ClientTeamContext::actorId();
            // FS-206a: somebody of the freelancers' team took it for the Owner who signed.
            $e->acted_by_freelancer_id ??= \Modules\Freelance\Services\FreelancerTeam\FreelancerTeamContext::actorId();
        });
    }


    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }
}
