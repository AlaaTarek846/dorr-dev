<?php

namespace Modules\Sports\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A club or a national team, with the colours its line-ups wear. */
class SportsTeam extends Model
{
    use HasTranslations;

    protected $fillable = ['sport_id', 'provider_id', 'name', 'code', 'logo', 'country', 'national', 'colors', 'founded', 'venue', 'coach', 'captain', 'profile_synced_at', 'season_synced_at'];

    protected function casts(): array
    {
        return [
            'national' => 'boolean', 'colors' => 'array', 'provider_id' => 'integer', 'founded' => 'integer', 'venue' => 'array', 'coach' => 'array',
            'captain' => 'array', 'profile_synced_at' => 'datetime', 'season_synced_at' => 'datetime',
        ];
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(SportsSport::class, 'sport_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SportsTeamTranslation::class);
    }

    protected function translationModel(): string
    {
        return SportsTeamTranslation::class;
    }

    public function displayName(): string
    {
        return ($this->relationLoaded('translations') ? $this->translated('name') : null) ?: $this->name;
    }
}
