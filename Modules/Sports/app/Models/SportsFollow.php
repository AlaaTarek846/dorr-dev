<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Support\ParticipantType;

/** A team, national team or competition I follow — with my alert choices for it (189, 193). */
class SportsFollow extends Model
{
    public const KINDS = ['team', 'competition'];

    /** Every alert there is, and what it starts as. */
    public const ALERTS = [
        'reminder' => true, 'kickoff' => true, 'goal' => true, 'red_card' => true, 'half_time' => false,
        'finished' => true, 'schedule' => true, 'lineups' => false,
    ];

    protected $fillable = ['owner_type', 'owner_id', 'sport_id', 'kind', 'target_id', 'alerts', 'no_spoilers'];

    protected function casts(): array
    {
        return ['alerts' => 'array', 'no_spoilers' => 'boolean', 'target_id' => 'integer'];
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }

    public function wants(string $alert): bool
    {
        return (bool) (($this->alerts ?? [])[$alert] ?? self::ALERTS[$alert] ?? false);
    }

    /** @return array<string, bool> */
    public function alertMap(): array
    {
        return collect(self::ALERTS)->mapWithKeys(fn ($default, $key) => [$key => $this->wants($key)])->all();
    }
}
