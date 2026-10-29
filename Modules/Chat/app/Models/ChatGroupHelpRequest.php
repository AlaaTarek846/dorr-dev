<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Asking for help in an educational / work group (GR-031, GR-041): to one person, or to the teachers / the team. */
class ChatGroupHelpRequest extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'participant_id', 'to_participant_id', 'question', 'status', 'answer', 'answered_by_participant_id', 'answered_at'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }
}
