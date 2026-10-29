<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A member's shift in a work group (GR-040), its hand-over note, and a swap waiting for another member. */
class ChatGroupShift extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'participant_id', 'title', 'starts_at', 'ends_at', 'handover_note', 'handed_over_at', 'swap_with_participant_id', 'created_by_participant_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'handed_over_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }
}
