<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Chat\Enums\CallStatus;
use Modules\Chat\Enums\CallType;

class ChatCall extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'type', 'status', 'initiator_participant_id', 'room_name', 'answered_at', 'ended_at'];

    protected function casts(): array
    {
        return [
            'type' => CallType::class,
            'status' => CallStatus::class,
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $call) {
            $call->uuid ??= (string) Str::uuid();
            $call->room_name ??= 'call_'.Str::lower(Str::random(24));
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

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(ChatParticipant::class, 'initiator_participant_id');
    }

    public function callParticipants(): HasMany
    {
        return $this->hasMany(ChatCallParticipant::class, 'call_id');
    }

    public function durationSeconds(): ?int
    {
        if ($this->answered_at === null) {
            return null;
        }

        return (int) $this->answered_at->diffInSeconds($this->ended_at ?? now());
    }
}
