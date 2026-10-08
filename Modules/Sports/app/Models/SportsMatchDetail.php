<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Model;

/** A match's statistics and line-ups, as the provider gives them (normalised). */
class SportsMatchDetail extends Model
{
    protected $fillable = ['match_id', 'statistics', 'lineups', 'players'];

    protected function casts(): array
    {
        return ['statistics' => 'array', 'lineups' => 'array', 'players' => 'array'];
    }
}
