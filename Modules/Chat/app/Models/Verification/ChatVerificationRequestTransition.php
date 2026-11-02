<?php

namespace Modules\Chat\Models\Verification;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Enums\VerificationRequestStatus;

/** One move of a verification request between statuses (REQ-10-05) — written only by VerificationRequestService. */
class ChatVerificationRequestTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['request_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'reason'];

    protected function casts(): array
    {
        return ['from_status' => VerificationRequestStatus::class, 'to_status' => VerificationRequestStatus::class, 'actor_id' => 'integer'];
    }
}
