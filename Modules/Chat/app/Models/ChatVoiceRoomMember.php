<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone in (or once in) a voice room: host · speaker · listener, a raised hand, a heartbeat. */
class ChatVoiceRoomMember extends Model
{
    public const ROLES = ['host', 'speaker', 'listener'];

    protected $fillable = ['room_id', 'participant_id', 'role', 'hand_raised_at', 'joined_at', 'left_at', 'seen_at'];

    protected function casts(): array
    {
        return ['hand_raised_at' => 'datetime', 'joined_at' => 'datetime', 'left_at' => 'datetime', 'seen_at' => 'datetime'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatVoiceRoom::class, 'room_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'participant_id');
    }

    public function canSpeak(): bool
    {
        return $this->role !== 'listener';
    }
}
