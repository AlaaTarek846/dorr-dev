<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A handoff (spec 152): a message, file or request handed to someone allowed, who takes it on. */
class ChatHandoff extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'source_message_id', 'card_message_id', 'from_participant_id', 'to_participant_id', 'note', 'status', 'responded_at', 'done_at'];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public const OPEN = ['pending', 'accepted'];

    public function from(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'from_participant_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'to_participant_id');
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
