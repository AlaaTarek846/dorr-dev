<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Chat\Support\ParticipantType;

/**
 * A broadcast list (like WhatsApp's): people I send the same message to — each one gets it in
 * our own one-to-one chat, and nobody sees who else is on the list.
 */
class ChatBroadcastList extends Model
{
    protected $fillable = ['uuid', 'owner_type', 'owner_id', 'name'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatBroadcastListMember::class, 'list_id');
    }

    public function sent(): HasMany
    {
        return $this->hasMany(ChatBroadcastMessage::class, 'list_id');
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
