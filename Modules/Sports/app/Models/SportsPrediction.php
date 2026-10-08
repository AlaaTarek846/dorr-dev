<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Chat\Support\ParticipantType;

/** My free prediction for a match — locked at kick-off, scored from the official result (196). */
class SportsPrediction extends Model
{
    public const EXACT_POINTS = 3;

    public const WINNER_POINTS = 1;

    protected $fillable = ['match_id', 'owner_type', 'owner_id', 'winner', 'home_score', 'away_score', 'points', 'exact', 'correct_winner', 'result', 'settled_at'];

    protected function casts(): array
    {
        return ['exact' => 'boolean', 'correct_winner' => 'boolean', 'settled_at' => 'datetime', 'points' => 'integer', 'home_score' => 'integer', 'away_score' => 'integer'];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(SportsMatch::class, 'match_id');
    }

    public function scopeOwnedBy(Builder $query, Model $owner): Builder
    {
        return $query->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey());
    }
}
