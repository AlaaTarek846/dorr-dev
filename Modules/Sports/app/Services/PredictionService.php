<?php

namespace Modules\Sports\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Support\ParticipantType;
use Modules\Sports\Exceptions\SportsException;
use Modules\Sports\Models\SportsMatch;
use Modules\Sports\Models\SportsPrediction;
use Modules\Sports\Models\SportsSetting;

/**
 * Predictions (196): free — never a stake — one per person per match, changeable until kick-off,
 * then locked. Scored only from the provider's official result; a cancelled or postponed match voids
 * them. The crowd's split is shown as entertainment.
 */
class PredictionService
{
    /**
     * @param  array{winner?: ?string, home_score?: ?int, away_score?: ?int}  $data
     */
    public function predict(Model $me, SportsMatch $match, array $data): SportsPrediction
    {
        if (! SportsSetting::current()->predictions_enabled) {
            throw new SportsException('predictions_off', 403);
        }
        if ($match->status !== 'scheduled' || $match->starts_at === null || $match->starts_at->lte(now())) {
            throw new SportsException('prediction_locked', 422);
        }
        $home = $data['home_score'] ?? null;
        $away = $data['away_score'] ?? null;
        $winner = $home !== null && $away !== null ? ($home > $away ? 'home' : ($home < $away ? 'away' : 'draw')) : ($data['winner'] ?? null);
        if (! in_array($winner, ['home', 'draw', 'away'], true)) {
            throw new SportsException('prediction_invalid', 422);
        }

        return SportsPrediction::query()->updateOrCreate(
            ['match_id' => $match->id, 'owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey()],
            ['winner' => $winner, 'home_score' => $home, 'away_score' => $away],
        );
    }

    /**
     * What everyone predicted: shares of home / draw / away, and the most picked score.
     *
     * @return array{count: int, home: int, draw: int, away: int, top_score: ?string}
     */
    public function crowd(SportsMatch $match): array
    {
        $rows = SportsPrediction::query()->where('match_id', $match->id)->select('winner', DB::raw('count(*) as n'))->groupBy('winner')->pluck('n', 'winner');
        $total = (int) $rows->sum();
        $pct = fn (string $k) => $total > 0 ? (int) round(100 * ((int) ($rows[$k] ?? 0)) / $total) : 0;
        $top = SportsPrediction::query()->where('match_id', $match->id)->whereNotNull('home_score')
            ->select('home_score', 'away_score', DB::raw('count(*) as n'))->groupBy('home_score', 'away_score')->orderByDesc('n')->first();

        return ['count' => $total, 'home' => $pct('home'), 'draw' => $pct('draw'), 'away' => $pct('away'), 'top_score' => $top ? $top->home_score.'-'.$top->away_score : null];
    }

    /** Score every prediction once the official result is in (or void them). */
    public function settle(SportsMatch $match): int
    {
        $void = in_array($match->status, ['cancelled', 'postponed'], true);
        if (! $void && ($match->status !== 'finished' || $match->home_score === null || $match->away_score === null)) {
            return 0;
        }
        $winner = $void ? null : ($match->home_score > $match->away_score ? 'home' : ($match->home_score < $match->away_score ? 'away' : 'draw'));
        $n = 0;
        SportsPrediction::query()->where('match_id', $match->id)->whereNull('settled_at')->chunkById(500, function ($rows) use ($match, $winner, $void, &$n) {
            foreach ($rows as $p) {
                $exact = ! $void && $p->home_score !== null && $p->home_score === $match->home_score && $p->away_score === $match->away_score;
                $right = ! $void && $p->winner === $winner;
                $p->update([
                    'exact' => $void ? null : $exact,
                    'correct_winner' => $void ? null : $right,
                    'points' => $void ? 0 : ($exact ? SportsPrediction::EXACT_POINTS : ($right ? SportsPrediction::WINNER_POINTS : 0)),
                    'result' => $void ? 'void' : ($right || $exact ? 'won' : 'lost'),
                    'settled_at' => now(),
                ]);
                $n++;
            }
        });

        return $n;
    }

    /** @return array<string, mixed>|null */
    public function present(?SportsPrediction $p): ?array
    {
        return $p === null ? null : [
            'winner' => $p->winner, 'home_score' => $p->home_score, 'away_score' => $p->away_score,
            'points' => $p->points, 'exact' => $p->exact, 'correct_winner' => $p->correct_winner, 'result' => $p->result,
        ];
    }
}
