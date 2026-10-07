<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Remind me about this message" at a time I picked (spec 47, 121) — mine only.
 */
class ChatMessageReminder extends Model
{
    protected $fillable = ['message_id', 'participant_id', 'remind_at', 'note', 'sent_at'];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'participant_id');
    }
}
