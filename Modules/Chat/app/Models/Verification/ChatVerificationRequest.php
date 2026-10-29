<?php

namespace Modules\Chat\Models\Verification;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Chat\Enums\VerificationActivationTiming;
use Modules\Chat\Enums\VerificationRequestStatus;
use Modules\Chat\Enums\VerificationTrack;
use Modules\User\Models\User;

/**
 * An account's verification request (AV-010). Its status moves only through VerificationRequestService,
 * which checks VerificationRequestStatus::transitions() and writes each move to `transitions`.
 * `open_key` holds the user id while the request is open, so one account has one open request.
 */
class ChatVerificationRequest extends Model
{
    protected $fillable = [
        'uuid', 'user_id', 'track', 'status', 'open_key', 'full_name', 'contact_phone', 'contact_email', 'notes',
        'deadline_at', 'deadline_owner', 'deadline_paused_at', 'deadline_remaining_seconds', 'deadline_warned_at',
        'suspended_from', 'identity_checked_at', 'identity_checked_by', 'completion_reason', 'completion_document_type_ids',
        'decision_reason', 'decided_by', 'decided_at', 'activation_timing', 'submitted_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => VerificationRequestStatus::class,
            'track' => VerificationTrack::class,
            'suspended_from' => VerificationRequestStatus::class,
            'activation_timing' => VerificationActivationTiming::class,
            'user_id' => 'integer',
            'deadline_at' => 'datetime',
            'deadline_paused_at' => 'datetime',
            'deadline_remaining_seconds' => 'integer',
            'deadline_warned_at' => 'datetime',
            'identity_checked_at' => 'datetime',
            'identity_checked_by' => 'integer',
            'completion_document_type_ids' => 'array',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'submitted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $r) => $r->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ChatVerificationRequestDocument::class, 'request_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(ChatVerificationRequestTransition::class, 'request_id')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return ! $this->status->isClosed();
    }

    /** The documents in force: one per type and side set, the newest not replaced. */
    public function currentDocuments()
    {
        return $this->documents->where('status', '!=', ChatVerificationRequestDocument::REPLACED)->sortByDesc('id')->unique('document_type_id')->values();
    }
}
