<?php

namespace Modules\Sports\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A prediction contest the admin runs on a match, a round or a competition (docs/sports-plan.md §5.2). */
class SportsContest extends Model
{
    use HasTranslations;

    public const SCOPES = ['match', 'round', 'competition'];

    public const RULES = ['exact', 'winner', 'points'];

    public const PRIZES = ['wallet', 'coupon', 'badge'];

    public const DISTRIBUTIONS = ['each', 'split', 'first_n'];

    protected $fillable = [
        'uuid', 'scope', 'match_id', 'competition_id', 'round', 'starts_at', 'ends_at', 'countries', 'rule', 'status', 'prize_type', 'prize_amounts',
        'coupon', 'distribution', 'max_winners', 'budget_minor', 'min_account_days', 'auto_pay', 'review_above_minor', 'seed', 'created_by', 'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'settled_at' => 'datetime', 'countries' => 'array', 'prize_amounts' => 'array', 'coupon' => 'array',
            'auto_pay' => 'boolean', 'max_winners' => 'integer', 'budget_minor' => 'integer', 'min_account_days' => 'integer', 'review_above_minor' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SportsContestTranslation::class);
    }

    protected function translationModel(): string
    {
        return SportsContestTranslation::class;
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(SportsMatch::class, 'match_id');
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(SportsCompetition::class, 'competition_id');
    }

    public function winners(): HasMany
    {
        return $this->hasMany(SportsContestWinner::class, 'contest_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function runsIn(?int $countryId): bool
    {
        return empty($this->countries) || ($countryId !== null && in_array($countryId, array_map('intval', $this->countries), true));
    }

    /** The matches it covers (for a round / competition: within its dates). */
    public function matchesQuery(): Builder
    {
        return match ($this->scope) {
            'match' => SportsMatch::query()->whereKey($this->match_id),
            'round' => SportsMatch::query()->where('competition_id', $this->competition_id)->where('round', $this->round),
            default => SportsMatch::query()->where('competition_id', $this->competition_id)
                ->when($this->starts_at, fn ($q) => $q->where('starts_at', '>=', $this->starts_at))
                ->when($this->ends_at, fn ($q) => $q->where('starts_at', '<=', $this->ends_at)),
        };
    }
}
