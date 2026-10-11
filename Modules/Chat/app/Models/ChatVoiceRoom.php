<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A group's or channel's standing voice room (spec 91). */
class ChatVoiceRoom extends Model
{
    public const POLICIES = ['everyone', 'approved'];

    protected $fillable = ['uuid', 'conversation_id', 'room_name', 'title', 'speak_policy', 'created_by_participant_id', 'last_active_at'];

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatVoiceRoomMember::class, 'room_id');
    }

    /** In the room now (joined, not left). */
    public function present(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }
}
