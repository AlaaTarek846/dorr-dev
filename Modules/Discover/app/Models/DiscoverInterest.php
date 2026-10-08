<?php

namespace Modules\Discover\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Support\ParticipantType;

/** "Interested" — saved, in my calendar, and (if I want) told when it changes (spec 177). */
class DiscoverInterest extends Model
{
    protected $fillable = ['event_id', 'owner_type', 'owner_id', 'notify'];

    protected function casts(): array
    {
        return ['notify' => 'boolean'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(DiscoverEvent::class, 'event_id');
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }
}
