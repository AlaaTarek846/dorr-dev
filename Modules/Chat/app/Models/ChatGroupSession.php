<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A class session (GR-017): its time, and who attended. */
class ChatGroupSession extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'space_id', 'title', 'starts_at', 'ends_at', 'created_by_participant_id', 'reminded_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(ChatGroupAttendance::class, 'session_id');
    }
}
