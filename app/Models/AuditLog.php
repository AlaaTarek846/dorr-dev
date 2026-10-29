<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One audited action (append-only): written only through App\Services\General\AuditLogger. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'reason', 'ip'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'actor_id' => 'integer', 'subject_id' => 'integer'];
    }
}
