<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of a table (or a group), as of the provider's last update (192). */
class SportsStanding extends Model
{
    protected $fillable = [
        'competition_id', 'season', 'group_name', 'rank', 'team_id', 'points', 'played', 'win', 'draw', 'lose', 'goals_for', 'goals_against', 'goal_diff', 'home', 'away',
        'form', 'description', 'trend', 'provider_updated_at',
    ];

    protected function casts(): array
    {
        return ['provider_updated_at' => 'datetime', 'home' => 'array', 'away' => 'array'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(SportsTeam::class, 'team_id');
    }
}
