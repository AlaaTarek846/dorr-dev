<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** An idea in a work group's ideas bank (GR-044): votes and a status. */
class ChatGroupIdea extends Model
{
    public const STATUSES = ['new', 'under_review', 'accepted', 'rejected', 'done'];

    protected $fillable = ['uuid', 'conversation_id', 'participant_id', 'title', 'body', 'status', 'status_note', 'votes'];

    protected function casts(): array
    {
        return ['votes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }
}
