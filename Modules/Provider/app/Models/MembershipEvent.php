<?php

namespace Modules\Provider\Models;

use Illuminate\Database\Eloquent\Model;

/** Who invited, removed or changed whom, and what changed (REQ-002). Rows are only ever added. */
class MembershipEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'provider_membership_events';

    protected $fillable = ['establishment_id', 'membership_id', 'actor_id', 'action', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
