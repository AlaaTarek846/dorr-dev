<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Chat\Support\ParticipantType;

/** One card many people sign before the organiser sends it (spec 163). */
class ChatCollabCard extends Model
{
    protected $fillable = ['uuid', 'owner_type', 'owner_id', 'recipient_type', 'recipient_id', 'chat_moment_id', 'personal_kind', 'title', 'deadline_at', 'status', 'message_id'];

    protected function casts(): array
    {
        return ['deadline_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatCollabCardMember::class, 'collab_card_id');
    }

    public function moment(): BelongsTo
    {
        return $this->belongsTo(ChatMoment::class, 'chat_moment_id');
    }

    public function isOwnedBy(Model $who): bool
    {
        return $this->owner_type === ParticipantType::aliasFor($who) && (int) $this->owner_id === (int) $who->getKey();
    }

    public function memberRow(Model $who): ?ChatCollabCardMember
    {
        return $this->members()->where('member_type', ParticipantType::aliasFor($who))->where('member_id', $who->getKey())->first();
    }

    public function scopeVisibleTo(Builder $query, Model $who): Builder
    {
        $type = ParticipantType::aliasFor($who);

        return $query->where(fn ($q) => $q->where(fn ($q) => $q->where('owner_type', $type)->where('owner_id', $who->getKey()))
            ->orWhereHas('members', fn ($m) => $m->where('member_type', $type)->where('member_id', $who->getKey())));
    }
}
