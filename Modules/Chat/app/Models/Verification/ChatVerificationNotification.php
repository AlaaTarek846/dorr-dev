<?php

namespace Modules\Chat\Models\Verification;

use Illuminate\Database\Eloquent\Model;

/** One notification a verification event sent (REQ-09-07) — written only by VerificationNotifier. */
class ChatVerificationNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['event', 'audience', 'recipient_type', 'recipient_id', 'subject_type', 'subject_id', 'push', 'status', 'error'];

    protected function casts(): array
    {
        return ['recipient_id' => 'integer', 'subject_id' => 'integer', 'push' => 'boolean'];
    }
}
