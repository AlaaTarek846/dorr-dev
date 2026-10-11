<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Model;

/** My Sports choices (194, 197, 198): no spoilers, celebrations, sound, the reminder. */
class SportsPreference extends Model
{
    public const CELEBRATIONS = ['off', 'calm', 'normal', 'festive'];

    protected $fillable = ['owner_type', 'owner_id', 'no_spoilers', 'celebration', 'sounds', 'vibrate', 'reminder_minutes', 'goals_in_quiet'];

    protected $attributes = ['no_spoilers' => false, 'celebration' => 'normal', 'sounds' => true, 'vibrate' => true, 'reminder_minutes' => 15, 'goals_in_quiet' => false];

    protected function casts(): array
    {
        return ['no_spoilers' => 'boolean', 'sounds' => 'boolean', 'vibrate' => 'boolean', 'reminder_minutes' => 'integer', 'goals_in_quiet' => 'boolean'];
    }
}
