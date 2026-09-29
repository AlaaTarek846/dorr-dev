<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageReaction extends Model
{
    protected $fillable = ['message_id', 'participant_id', 'emoji'];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'participant_id');
    }
}
