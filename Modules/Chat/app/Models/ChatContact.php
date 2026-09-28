<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Support\ParticipantType;

/**
 * A number in someone's address book — synced from the phone, typed in, or added by QR.
 * contact_type/contact_id point at the registered account behind the number, when there is one.
 */
class ChatContact extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'name', 'phone', 'contact_type', 'contact_id', 'is_favorite', 'source'];

    protected function casts(): array
    {
        return ['is_favorite' => 'boolean'];
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }

    public function isRegistered(): bool
    {
        return $this->contact_id !== null;
    }
}
