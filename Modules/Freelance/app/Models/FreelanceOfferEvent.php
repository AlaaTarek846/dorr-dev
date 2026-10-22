<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;

/** One thing that happened to an offer (FL-301): who, from which status to which, when. Written once, never changed. */
class FreelanceOfferEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['offer_id', 'type', 'from_status', 'to_status', 'actor_type', 'actor_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }
}
