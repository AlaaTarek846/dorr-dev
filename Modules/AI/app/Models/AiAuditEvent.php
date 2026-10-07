<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit ledger - v2.0 requirements doc S17.6. Deliberately no
 * update()/delete() support: see AiAuditTrail::record(), the only
 * intended way rows are written, and the model overrides below, which
 * make tampering fail loudly instead of silently succeeding if some
 * future code ever tries.
 */
class AiAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'actor_type',
        'actor_id',
        'event_type',
        'severity',
        'subject_type',
        'subject_id',
        'trace_id',
        'ip_address',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * owner_type/actor_type only ever hold 'user'/'provider' (the morph
     * map registered in AIServiceProvider::boot()) - see AiAuditTrail::record().
     * subject_type is deliberately NOT a relation here: it names whatever
     * internal entity the event is about (a conversation, a knowledge
     * source...), which is not in that morph map, so it's displayed as a
     * plain type+id pair instead of an eager-loaded relation.
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('AiAuditEvent rows are append-only and cannot be updated.');
    }

    public function delete(): ?bool
    {
        throw new \LogicException('AiAuditEvent rows are append-only and cannot be deleted.');
    }
}
