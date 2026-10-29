<?php

namespace Modules\Chat\Models\Verification;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Enums\VerificationBadgeStatus;

/** One change of a badge (REQ-10-05) — written only by VerificationBadgeService. */
class ChatVerificationBadgeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['badge_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'reason', 'changes'];

    protected function casts(): array
    {
        return ['from_status' => VerificationBadgeStatus::class, 'to_status' => VerificationBadgeStatus::class, 'actor_id' => 'integer', 'changes' => 'array'];
    }
}
