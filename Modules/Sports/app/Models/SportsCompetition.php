<?php

namespace Modules\Sports\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A competition from the provider (184): league or cup, with its current season. Its tier decides
 * how often it's updated during a match (big · normal · minor) — "off" means not followed at all.
 */
class SportsCompetition extends Model
{
    use HasTranslations;

    public const TIERS = ['big', 'normal', 'minor', 'off'];

    public const SCOPES = ['international', 'continental', 'regional', 'domestic'];

    protected $fillable = [
        'sport_id', 'provider_id', 'name', 'type', 'scope', 'country_name', 'country_code', 'logo', 'flag', 'season', 'season_start', 'season_end',
        'coverage', 'tier', 'priority', 'top_scorers', 'standings_synced_at', 'scorers_synced_at', 'standings_dirty', 'season_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'coverage' => 'array', 'top_scorers' => 'array', 'season_start' => 'date', 'season_end' => 'date', 'priority' => 'integer',
            'standings_synced_at' => 'datetime', 'scorers_synced_at' => 'datetime', 'season_synced_at' => 'datetime', 'standings_dirty' => 'boolean', 'provider_id' => 'integer',
        ];
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(SportsSport::class, 'sport_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(SportsMatch::class, 'competition_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SportsCompetitionTranslation::class);
    }

    protected function translationModel(): string
    {
        return SportsCompetitionTranslation::class;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('tier', '!=', 'off');
    }

    /** The admin's name in my language, else the provider's. */
    public function displayName(): string
    {
        return $this->translated('name') ?: $this->name;
    }

    public function covers(string $what): bool
    {
        return (bool) data_get($this->coverage, $what, true);
    }
}
