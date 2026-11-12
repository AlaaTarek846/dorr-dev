<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An explicit approval of one exact version (POL-01/02): it names the version and the hash of its content, so any
 * change to the subject leaves it behind (`superseded`).
 */
class MarketingApproval extends Model
{
    public const ACTIVE = 'active';

    public const SUPERSEDED = 'superseded';

    public const REVOKED = 'revoked';

    protected $fillable = ['uuid', 'project_id', 'subject_type', 'subject_id', 'subject_version', 'payload_hash', 'scope', 'cap_minor', 'currency', 'status', 'actor_type', 'actor_id', 'delegate_type', 'delegate_id', 'delegation_id', 'approved_at', 'expires_at', 'revoked_at'];

    protected static function booted(): void
    {
        static::creating(function (self $approval) {
            $approval->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'subject_version' => 'integer', 'cap_minor' => 'integer'];
    }
}
