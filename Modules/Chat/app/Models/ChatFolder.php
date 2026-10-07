<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Chat\Support\ParticipantType;

/**
 * A person's own chat list ("Family", "Work"…) — LeeTaxi's "societies".
 */
class ChatFolder extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'name', 'color', 'emoji', 'sort_order'];

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(ChatConversation::class, 'chat_folder_conversations', 'folder_id', 'conversation_id');
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }
}
