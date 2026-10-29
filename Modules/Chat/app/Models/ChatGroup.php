<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Enums\GroupKind;
use Spatie\MediaLibrary\HasMedia;

class ChatGroup extends Model implements HasMedia
{
    use HasMediaTrait;

    protected $fillable = [
        'conversation_id',
        // normal | education | work — fixed once the group exists (GroupKind).
        'kind',
        // In a community (WhatsApp list 93–98).
        'community_id',
        'name',
        'description',
        'invite_token',
        'invite_expires_at',
        'only_admins_send',
        'only_admins_edit_info',
        'only_admins_add_members',
        'approve_joins',
        // direct | approval | request (GR-003) — approve_joins is on for the last two.
        'join_mode',
        'slow_mode_seconds',
        'banned_words',
        'handle',
        'is_public',
        'category_id',
        'verified_until',
        'verified_by_admin',
        'allowed_reactions',
        // Paid: members' numbers hidden from each other (ChatGroup::membersHidden()).
        'members_hidden_at',
        // Educational / work groups: read only while archived; a deleted one can come back until purge_after (GR-006).
        'archived_at',
        // Turned off by the admin: read only, the owner can't undo it (GR-050).
        'disabled_at',
        // Linked parents may message the teachers privately (GR-019).
        'parents_can_message',
        'purge_after',
        // A term / subgroup / project: the main group's conversation id (GR-007).
        'space_of_conversation_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => GroupKind::class,
            'only_admins_send' => 'boolean',
            'only_admins_edit_info' => 'boolean',
            'only_admins_add_members' => 'boolean',
            'approve_joins' => 'boolean',
            'is_public' => 'boolean',
            'verified_until' => 'datetime',
            'verified_by_admin' => 'boolean',
            'slow_mode_seconds' => 'integer',
            'banned_words' => 'array',
            'invite_expires_at' => 'datetime',
            'allowed_reactions' => 'array',
            'members_hidden_at' => 'datetime',
            'archived_at' => 'datetime',
            'disabled_at' => 'datetime',
            'parents_can_message' => 'boolean',
            'purge_after' => 'datetime',
        ];
    }

    /** How people join (docs/groups_tasks.md GR-003). */
    public const JOIN_MODES = ['direct', 'approval', 'request'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function avatarUrl(): ?string
    {
        return $this->getSingleMediaUrl('avatar') ?: null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChatCategory::class, 'category_id');
    }

    /** The ✔ next to a channel's name: verified by hand, or a paid verification still running. */
    public function isVerified(): bool
    {
        return $this->verified_by_admin || ($this->verified_until !== null && $this->verified_until->isFuture());
    }

    /** Hidden members (paid): numbers hidden, no private chats started from here — names still show. */
    public function membersHidden(): bool
    {
        return $this->members_hidden_at !== null;
    }
}
