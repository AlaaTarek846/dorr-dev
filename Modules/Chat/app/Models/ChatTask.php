<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Support\ParticipantType;

/** One of my tasks (spec 38) — optionally from a message, optionally due at a time. */
class ChatTask extends Model
{
    protected $fillable = ['uuid', 'owner_type', 'owner_id', 'text', 'due_at', 'reminded_at', 'done_at', 'message_id', 'conversation_id', 'sort_order'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'reminded_at' => 'datetime', 'done_at' => 'datetime', 'sort_order' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }

    public function isOwnedBy(Model $owner): bool
    {
        return $this->owner_type === ParticipantType::aliasFor($owner) && (int) $this->owner_id === (int) $owner->getKey();
    }
}
