<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;

/** A set of up to 3 reference photos of one person, usable only after the owner's explicit consent. */
class AiWorldIdentityProfile extends Model
{
    public const PENDING_CONSENT = 'pending_consent';

    public const ACTIVE = 'active';

    public const DELETED = 'deleted';

    public const SUBJECT_SELF = 'self';

    public const SUBJECT_OTHER = 'other_with_permission';

    protected $table = 'ai_world_identity_profiles';

    protected $fillable = ['owner_type', 'owner_id', 'label', 'subject_type', 'status', 'consent_id', 'reference_files', 'expires_at', 'deleted_at'];

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'reference_files' => 'array', 'expires_at' => 'datetime', 'deleted_at' => 'datetime'];
    }
}
