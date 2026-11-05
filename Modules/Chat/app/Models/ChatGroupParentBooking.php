<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Model;

/** One parent's time in a parents' meeting with slots (GR-027) — one per child, one parent per slot. */
class ChatGroupParentBooking extends Model
{
    protected $fillable = ['meeting_id', 'link_id', 'slot_at', 'reminded_at'];

    protected function casts(): array
    {
        return ['slot_at' => 'datetime', 'reminded_at' => 'datetime'];
    }
}
