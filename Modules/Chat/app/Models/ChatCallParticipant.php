<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Enums\CallParticipantStatus;

class ChatCallParticipant extends Model
{
    protected $fillable = ['call_id', 'participant_id', 'status', 'joined_at', 'left_at', 'silenced'];

    protected function casts(): array
    {
        return [
            'status' => CallParticipantStatus::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'silenced' => 'boolean',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'participant_id');
    }
}
