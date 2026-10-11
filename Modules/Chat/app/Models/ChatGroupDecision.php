<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A decision in a group (spec 119–120): made from a message, voted on, approved by an admin. */
class ChatGroupDecision extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'source_message_id', 'poll_message_id', 'title', 'description', 'deadline_at', 'status', 'outcome', 'created_by_participant_id', 'decided_by_participant_id', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'deadline_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /** Everyone's arguments in the decision room (spec 153). */
    public function arguments(): HasMany
    {
        return $this->hasMany(ChatDecisionArgument::class, 'decision_id');
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'poll_message_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'source_message_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'created_by_participant_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'decided_by_participant_id');
    }
}
