<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Support\ParticipantType;

/** One of my own dates — a birthday, an anniversary — optionally tied to someone on Dorr. */
class ChatPersonalMoment extends Model
{
    public const KINDS = ['birthday', 'anniversary', 'graduation', 'wedding', 'baby', 'other'];

    protected $fillable = ['uuid', 'owner_type', 'owner_id', 'kind', 'title', 'month', 'day', 'year', 'contact_type', 'contact_id', 'remind_days_before'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'day' => 'integer', 'year' => 'integer', 'remind_days_before' => 'integer', 'reminded_before_for' => 'date', 'reminded_day_for' => 'date'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
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
