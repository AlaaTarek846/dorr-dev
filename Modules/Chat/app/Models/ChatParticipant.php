<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Support\ParticipantType;

/**
 * One person in one conversation, plus everything that person decided about it for
 * themselves: pinned, archived, muted, locked, cleared, deleted, unread state, theme.
 */
class ChatParticipant extends Model
{
    protected $fillable = [
        'privacy_circle_id',
        'conversation_id',
        'participant_type',
        'participant_id',
        'role',
        'joined_at',
        'left_at',
        'last_read_message_id',
        'last_read_at',
        'last_delivered_message_id',
        'unread_count',
        'marked_unread',
        'has_unread_mention',
        'pinned_at',
        'is_archived',
        'is_locked',
        'lock_pin_hash',
        'lock_pin_failures',
        'lock_pin_until',
        'muted_until',
        'cleared_before_message_id',
        'is_deleted',
        'theme_id',
        'custom_theme',
    ];

    protected function casts(): array
    {
        return [
            'role' => ParticipantRole::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_read_at' => 'datetime',
            'pinned_at' => 'datetime',
            'muted_until' => 'datetime',
            'unread_count' => 'integer',
            'marked_unread' => 'boolean',
            'has_unread_mention' => 'boolean',
            'is_archived' => 'boolean',
            'is_locked' => 'boolean',
            'lock_pin_until' => 'datetime',
            'is_deleted' => 'boolean',
            'custom_theme' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /**
     * The account behind this row (a User today; a Provider / Driver later).
     */
    public function participant(): ?Model
    {
        return ParticipantType::modelClassFor($this->participant_type)::query()->find($this->participant_id);
    }

    public function scopeOf(Builder $query, Model $account): Builder
    {
        return $query->where('participant_type', ParticipantType::aliasFor($account))
            ->where('participant_id', $account->getKey());
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    public function isAdmin(): bool
    {
        return $this->isActive() && $this->role->isAdmin();
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function key(): string
    {
        return $this->participant_type.':'.$this->participant_id;
    }

    public function isSameAccount(Model $account): bool
    {
        return $this->participant_type === ParticipantType::aliasFor($account)
            && (int) $this->participant_id === (int) $account->getKey();
    }

    /** My privacy circle for this chat (spec 98). */
    public function privacyCircle(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ChatPrivacyCircle::class, 'privacy_circle_id');
    }
}
