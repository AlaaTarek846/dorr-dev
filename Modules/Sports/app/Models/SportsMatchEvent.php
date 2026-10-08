<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Model;

/** A goal, a card, a substitution… — `key` keeps the same event from being stored twice. */
class SportsMatchEvent extends Model
{
    public const GOALS = ['goal', 'own_goal', 'penalty'];

    protected $fillable = ['match_id', 'key', 'minute', 'extra', 'side', 'type', 'player', 'player_id', 'assist', 'assist_id', 'detail', 'sort'];

    protected function casts(): array
    {
        return ['minute' => 'integer', 'extra' => 'integer', 'sort' => 'integer'];
    }
}
