<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A call recording (spec 92): asked for, agreed to by everyone in the call (or not), recorded by
 * LiveKit Egress, then kept for the admin's retention days. Consents are in
 * `chat_call_recording_consents`.
 */
class ChatCallRecording extends Model
{
    /** Asking everyone, or recording now: what the call screen shows. */
    public const OPEN = ['pending', 'recording'];

    /** Recorded (or being saved): what the people who agreed can come back to. */
    public const KEPT = ['recording', 'processing', 'ready'];

    protected $fillable = [
        'uuid', 'call_id', 'conversation_id', 'requested_by_participant_id', 'status', 'consent_until', 'egress_id',
        'file_path', 'size_bytes', 'duration_seconds', 'error', 'started_at', 'stopped_at', 'stopped_by_participant_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_until' => 'datetime',
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
            'expires_at' => 'datetime',
            'size_bytes' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $recording) => $recording->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(ChatCall::class, 'call_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'requested_by_participant_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
