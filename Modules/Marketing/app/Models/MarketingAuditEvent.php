<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only (POL-08): a row is written once and never changed or removed by the app. */
class MarketingAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['project_id', 'actor_type', 'actor_id', 'action', 'resource_type', 'resource_id', 'before_hash', 'after_hash', 'request_id', 'meta'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Marketing audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Marketing audit events are append-only.'));
    }

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
