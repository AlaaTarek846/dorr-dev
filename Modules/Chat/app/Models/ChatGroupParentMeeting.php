<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** A parents' meeting (GR-027): one call for everyone (`group`) or times each parent books (`slots`). */
class ChatGroupParentMeeting extends Model
{
    protected $fillable = ['uuid', 'conversation_id', 'title', 'note', 'starts_at', 'mode', 'slot_minutes', 'slots', 'call_link_id', 'created_by_participant_id', 'reminded_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'reminded_at' => 'datetime', 'slot_minutes' => 'integer', 'slots' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function callLink()
    {
        return $this->belongsTo(ChatCallLink::class, 'call_link_id');
    }

    public function bookings()
    {
        return $this->hasMany(ChatGroupParentBooking::class, 'meeting_id');
    }

    /** @return list<Carbon> every slot's start (none for a meeting of everyone) */
    public function slotTimes(): array
    {
        if ($this->mode !== 'slots' || ! $this->slot_minutes || ! $this->slots) {
            return [];
        }

        return array_map(fn (int $i) => $this->starts_at->copy()->addMinutes($i * $this->slot_minutes), range(0, $this->slots - 1));
    }
}
