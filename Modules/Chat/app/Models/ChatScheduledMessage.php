<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Chat\Support\ParticipantType;

/**
 * A text message written now and sent at `send_at` by `chat:send-scheduled`.
 */
class ChatScheduledMessage extends Model implements \Spatie\MediaLibrary\HasMedia
{
    use \App\Traits\HasMediaTrait;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $fillable = [
        'uuid',
        'conversation_id',
        'owner_type',
        'owner_id',
        'body',
        'type',
        'meta',
        'timezone',
        'is_silent',
        'send_at',
        'status',
        'error_code',
        'message_id',
    ];

    protected function casts(): array
    {
        return [
            'is_silent' => 'boolean',
            'meta' => 'array',
            'send_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $row) {
            $row->uuid ??= (string) Str::uuid();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function owner(): ?Model
    {
        return ParticipantType::modelClassFor($this->owner_type)::query()->find($this->owner_id);
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->uuid,
            'conversation_id' => $this->conversation?->uuid,
            'body' => $this->body,
            'is_silent' => (bool) $this->is_silent,
            'send_at' => $this->send_at?->toIso8601String(),
            'type' => $this->type ?? 'text',
            'meta' => $this->meta,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'error_code' => $this->error_code,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
