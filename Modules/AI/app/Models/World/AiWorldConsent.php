<?php

namespace Modules\AI\Models\World;

use Illuminate\Database\Eloquent\Model;

/** Proof that the owner agreed to a specific version of the consent text. The full text is not copied per consent. */
class AiWorldConsent extends Model
{
    protected $table = 'ai_world_consents';

    protected $fillable = ['owner_type', 'owner_id', 'profile_id', 'text_version', 'scope', 'attestation', 'granted_at', 'ip_hash', 'revoked_at'];

    protected function casts(): array
    {
        return ['owner_id' => 'integer', 'scope' => 'array', 'granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
