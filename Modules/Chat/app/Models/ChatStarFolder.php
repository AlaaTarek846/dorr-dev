<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Support\ParticipantType;

/** One of my favourites folders (spec 24): starred messages sorted my way ("Work", "Recipes"…). */
class ChatStarFolder extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'name', 'emoji', 'color', 'sort_order'];

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }

    public function isOwnedBy(Model $owner): bool
    {
        return $this->owner_type === ParticipantType::aliasFor($owner) && (int) $this->owner_id === (int) $owner->getKey();
    }
}
