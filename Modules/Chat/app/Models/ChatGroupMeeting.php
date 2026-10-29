<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A meeting of a work group (GR-046): its time, agenda and minutes. */
class ChatGroupMeeting extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'space_id', 'title', 'starts_at', 'agenda', 'minutes', 'minutes_by_participant_id', 'created_by_participant_id', 'reminded_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }
}
