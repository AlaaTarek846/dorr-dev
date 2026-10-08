<?php

namespace Modules\Discover\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Support\ParticipantType;

/** A city or country I follow, wherever I am (spec 170). */
class DiscoverFollow extends Model
{
    public const KINDS = ['city', 'country'];

    protected $fillable = ['owner_type', 'owner_id', 'kind', 'target_id'];

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }
}
