<?php

namespace Modules\Chat\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Spatie\MediaLibrary\HasMedia;

class ChatMessage extends Model implements HasMedia
{
    use HasMediaTrait;

    public const ATTACHMENTS = 'attachments';

    /** A video's poster frame. */
    public const THUMBNAIL = 'thumbnail';

    protected $fillable = [
        'uuid',
        'conversation_id',
        'sender_type',
        'sender_id',
        'type',
        'body',
        'meta',
        'reply_to_id',
        'is_forwarded',
        'forward_score',
        'mentions',
        'has_link',
        'view_once',
        'edited_at',
        'deleted_for_everyone_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'meta' => 'array',
            'mentions' => 'array',
            'is_forwarded' => 'boolean',
            'forward_score' => 'integer',
            'has_link' => 'boolean',
            'view_once' => 'boolean',
            'edited_at' => 'datetime',
            'deleted_for_everyone_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $message) {
            $message->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatMessageReaction::class, 'message_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ChatMessageReceipt::class, 'message_id');
    }

    public function userStates(): HasMany
    {
        return $this->hasMany(ChatMessageUserState::class, 'message_id');
    }

    public function isDeletedForEveryone(): bool
    {
        return $this->deleted_for_everyone_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Content is gone (deleted for everyone, or a disappearing message whose time is up).
     */
    public function isGone(): bool
    {
        return $this->isDeletedForEveryone() || $this->isExpired();
    }

    public function isFrom(string $type, int $id): bool
    {
        return $this->sender_type === $type && (int) $this->sender_id === $id;
    }
}
