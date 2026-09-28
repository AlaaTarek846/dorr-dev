<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Chat\Enums\ConversationStatus;
use Modules\Chat\Enums\ConversationType;

class ChatConversation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'type',
        'status',
        'direct_key',
        'created_by_type',
        'created_by_id',
        'last_message_id',
        'last_message_at',
        'disappearing_seconds',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
            'disappearing_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $conversation) {
            $conversation->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isGroup(): bool
    {
        return $this->type === ConversationType::Group;
    }

    public function group(): HasOne
    {
        return $this->hasOne(ChatGroup::class, 'conversation_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    /**
     * People currently in the conversation (not left / removed).
     */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id');
    }

    public function pinnedMessages(): HasMany
    {
        return $this->hasMany(ChatPinnedMessage::class, 'conversation_id');
    }
}
