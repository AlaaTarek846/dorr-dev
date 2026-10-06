<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Chat\Support\ParticipantType;

/**
 * One of my privacy circles (spec 98–103). See the migration for what each setting does.
 */
class ChatPrivacyCircle extends Model
{
    /** From most to least revealing: P0 … P4. */
    public const LEVELS = ['all', 'name', 'circle', 'none', 'hidden'];

    protected $fillable = ['uuid', 'owner_type', 'owner_id', 'name', 'masked_name', 'emoji', 'color', 'disclosure', 'hide_from_list', 'locked', 'sort_order'];

    protected function casts(): array
    {
        return ['hide_from_list' => 'boolean', 'locked' => 'boolean', 'sort_order' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** My rows in the chats that sit in this circle. */
    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'privacy_circle_id');
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }

    public function isOwnedBy(Model $owner): bool
    {
        return $this->owner_type === ParticipantType::aliasFor($owner) && (int) $this->owner_id === (int) $owner->getKey();
    }

    /** What a notification (or the chip) calls it: the stand-in name when there is one. */
    public function shownName(): string
    {
        return $this->masked_name ?: $this->name;
    }

    /** The more private of two levels. */
    public static function stricter(string $a, string $b): string
    {
        return array_search($a, self::LEVELS, true) >= array_search($b, self::LEVELS, true) ? $a : $b;
    }
}
