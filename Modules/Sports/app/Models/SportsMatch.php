<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One fixture in any sport (game, race, fight…), with a status every sport shares:
 * scheduled · live · break · finished · postponed · cancelled · suspended.
 */
class SportsMatch extends Model
{
    public const LIVE = ['live', 'break'];

    public const DONE = ['finished', 'cancelled', 'postponed'];

    protected $fillable = [
        'uuid', 'sport_id', 'competition_id', 'provider_id', 'season', 'round', 'home_team_id', 'away_team_id', 'starts_at', 'status', 'status_code', 'status_label',
        'elapsed', 'elapsed_extra', 'home_score', 'away_score', 'scores', 'winner', 'venue', 'venue_id', 'city', 'referee', 'version', 'live_at', 'finished_at',
        'synced_at', 'details_synced_at', 'lineups_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'live_at' => 'datetime', 'finished_at' => 'datetime', 'synced_at' => 'datetime', 'details_synced_at' => 'datetime',
            'lineups_synced_at' => 'datetime', 'scores' => 'array', 'provider_id' => 'integer', 'version' => 'integer',
            'elapsed' => 'integer', 'elapsed_extra' => 'integer', 'home_score' => 'integer', 'away_score' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(SportsSport::class, 'sport_id');
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(SportsCompetition::class, 'competition_id');
    }

    public function home(): BelongsTo
    {
        return $this->belongsTo(SportsTeam::class, 'home_team_id');
    }

    public function away(): BelongsTo
    {
        return $this->belongsTo(SportsTeam::class, 'away_team_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SportsMatchEvent::class, 'match_id');
    }

    public function details(): HasOne
    {
        return $this->hasOne(SportsMatchDetail::class, 'match_id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function scopeInvolving(Builder $query, int $teamId): Builder
    {
        return $query->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId));
    }
}
