<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;

class ChatGroup extends Model implements HasMedia
{
    use HasMediaTrait;

    protected $fillable = [
        'conversation_id',
        'name',
        'description',
        'invite_token',
        'invite_expires_at',
        'only_admins_send',
        'only_admins_edit_info',
        'only_admins_add_members',
        'approve_joins',
        'slow_mode_seconds',
        'banned_words',
        'handle',
        'is_public',
        'category_id',
        'verified_until',
        'verified_by_admin',
    ];

    protected function casts(): array
    {
        return [
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
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function avatarUrl(): ?string
    {
        return $this->getSingleMediaUrl('avatar') ?: null;
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ChatCategory::class, 'category_id');
    }

    /** The ✔ next to a channel's name: verified by hand, or a paid verification still running. */
    public function isVerified(): bool
    {
        return $this->verified_by_admin || ($this->verified_until !== null && $this->verified_until->isFuture());
    }
}
