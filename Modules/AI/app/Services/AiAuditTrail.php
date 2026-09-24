<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Request as RequestFacade;
use Modules\AI\Models\AiAuditEvent;

/**
 * v2.0 requirements doc S17.6: a single, append-only place to write a
 * sensitive-event audit row from, regardless of which subsystem the
 * event came from. Every call site passes a caller-supplied event_type
 * constant (below) rather than a free string, so the set of recorded
 * event kinds stays enumerable and greppable.
 *
 * Deliberately best-effort: a failure to write an audit row must never
 * break the real operation it is auditing (an export that succeeded but
 * whose audit row failed to save is still a successful export from the
 * owner's point of view) - so record() swallows and reports failures
 * rather than throwing into the caller.
 */
class AiAuditTrail
{
    public const EVENT_DATA_EXPORTED = 'data_exported';

    public const EVENT_DATA_ERASED = 'data_erased';

    public const EVENT_SAFETY_RULE_TRIGGERED = 'safety_rule_triggered';

    public const EVENT_IDEMPOTENT_REPLAY = 'idempotent_replay';

    public const EVENT_CIRCUIT_BREAKER_OPENED = 'circuit_breaker_opened';

    public function record(
        string $eventType,
        ?Authenticatable $owner = null,
        string $severity = 'info',
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?string $traceId = null,
        array $metadata = [],
        ?Authenticatable $actor = null,
    ): void {
        try {
            AiAuditEvent::query()->create([
                'owner_type' => $owner?->getMorphClass(),
                'owner_id' => $owner?->getAuthIdentifier(),
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getAuthIdentifier(),
                'event_type' => $eventType,
                'severity' => $severity,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'trace_id' => $traceId,
                'ip_address' => RequestFacade::ip(),
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
