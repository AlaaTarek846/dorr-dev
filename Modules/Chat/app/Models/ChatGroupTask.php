<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Chat\Contracts\ProtectedMediaOwner;
use Modules\Chat\Support\GroupScope;
use Spatie\MediaLibrary\HasMedia;

/**
 * A task in a work group (docs/groups_tasks.md GR-033..036). Proof files are kept on the private
 * disk and read through signed links (GroupMedia), like the group's chat files.
 */
class ChatGroupTask extends Model implements HasMedia, ProtectedMediaOwner
{
    use HasMediaTrait;

    public const PROOF = 'proof';

    public const STATUSES = ['new', 'in_progress', 'in_review', 'done', 'cancelled'];

    public const OPEN = ['new', 'in_progress', 'in_review'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const REPEATS = ['daily', 'weekly', 'monthly'];

    protected $fillable = [
        'uuid', 'conversation_id', 'space_id', 'source_message_id', 'title', 'description', 'assignee_participant_id', 'created_by_participant_id',
        'priority', 'status', 'due_at', 'needs_approval', 'requires_proof', 'proof_note', 'review_note',
        'reviewed_by_participant_id', 'submitted_at', 'completed_at', 'repeat', 'repeat_until', 'series_id', 'next_spawned_at', 'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'needs_approval' => 'boolean',
            'requires_proof' => 'boolean',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'repeat_until' => 'date',
            'next_spawned_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $task) => $task->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(ChatGroupSpace::class, 'space_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'assignee_participant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'created_by_participant_id');
    }

    /** The message it was made from (GR-038) — shown only to someone who may still read it. */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'source_message_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChatGroupTaskItem::class, 'task_id')->orderBy('sort_order')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function protectedMediaAllows(Model $viewer): bool
    {
        return GroupScope::canSee($viewer, $this->conversation_id, $this->space_id);
    }

    public function hasProof(): bool
    {
        return filled($this->proof_note) || $this->getMedia(self::PROOF)->isNotEmpty();
    }
}
