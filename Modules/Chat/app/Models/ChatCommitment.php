<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A commitment (spec 149): who will do what, by when — the one responsible accepts it. */
class ChatCommitment extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'source_message_id', 'card_message_id', 'created_by_participant_id', 'assignee_participant_id', 'title', 'due_at', 'remind_minutes', 'status', 'responded_at', 'done_at', 'reminded_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'responded_at' => 'datetime', 'done_at' => 'datetime', 'reminded_at' => 'datetime', 'remind_minutes' => 'integer'];
    }

    public const STATUSES = ['proposed', 'accepted', 'declined', 'done', 'cancelled'];

    /** Still something to do. */
    public const OPEN = ['proposed', 'accepted'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'created_by_participant_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'assignee_participant_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'source_message_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'card_message_id');
    }
}
