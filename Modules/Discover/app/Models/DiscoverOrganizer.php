<?php

namespace Modules\Discover\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Chat\Support\ParticipantType;

/** Someone who submits events — trusted only once the admin verified them (spec 180, AT-DISC-02). */
class DiscoverOrganizer extends Model
{
    public const STATUSES = ['pending', 'verified', 'rejected', 'suspended'];

    protected $fillable = ['owner_type', 'owner_id', 'name', 'about', 'website', 'phone', 'email', 'status', 'review_note', 'verified_at', 'reviewed_by'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(DiscoverEvent::class, 'organizer_id');
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
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
