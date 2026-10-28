<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;

/**
 * A short satisfaction survey after a project (FL-702): from each side, once, within `OPEN_DAYS` of the project ending (completed, or
 * ended after it had started). A score 1-5; when it is 3 or less, at least one reason from the list (so the team can see WHY people are not
 * satisfied); and an optional comment. It is for the team only - the other side never sees it, and it changes nothing for anyone.
 */
class SatisfactionSurveys
{
    public const OPEN_DAYS = 30;

    /** What people could be unhappy about (the same list for both sides). */
    public const REASONS = ['quality', 'late', 'communication', 'price', 'scope', 'payment', 'platform', 'other'];

    /** A score of this or less asks for the reasons. */
    public const NOT_SATISFIED_MAX = 3;

    /** @return array<string, mixed> the survey of one agreement for one side */
    public function forAgreement(Agreement $a, string $side): array
    {
        $mine = DB::table('freelance_satisfaction_surveys')->where('agreement_id', $a->id)->where('side', $side)->first();

        return [
            'can_answer' => $mine === null && $this->open($a),
            'answer_until' => $this->endedAt($a)?->copy()->addDays(self::OPEN_DAYS)->toIso8601String(),
            'reasons' => self::REASONS,
            'not_satisfied_max' => self::NOT_SATISFIED_MAX,
            'mine' => $mine === null ? null : ['score' => (int) $mine->score, 'reasons' => json_decode((string) $mine->reasons, true) ?: [], 'comment' => $mine->comment],
        ];
    }

    /**
     * @param  list<string>  $reasons
     */
    public function answer(Agreement $a, Model $me, string $side, int $score, array $reasons, ?string $comment): void
    {
        abort_unless((int) ($side === 'client' ? $a->client_id : $a->freelancer_id) === (int) $me->getKey(), 404);
        $reasons = array_values(array_unique(array_intersect($reasons, self::REASONS)));
        if ($score < 1 || $score > 5) {
            throw new FreelanceException('survey_score', 422);
        }
        if ($score <= self::NOT_SATISFIED_MAX && $reasons === []) {
            throw new FreelanceException('survey_reasons_required', 422);
        }
        if (! $this->open($a)) {
            throw new FreelanceException('survey_closed', 409);
        }
        if (DB::table('freelance_satisfaction_surveys')->where('agreement_id', $a->id)->where('side', $side)->exists()) {
            throw new FreelanceException('survey_exists', 409);
        }
        DB::table('freelance_satisfaction_surveys')->insert([
            'agreement_id' => $a->id, 'project_id' => $a->project_id, 'side' => $side, 'score' => $score,
            // Reasons only matter when not satisfied.
            'reasons' => $score <= self::NOT_SATISFIED_MAX ? json_encode($reasons) : null,
            'comment' => $comment === null ? null : (mb_substr(trim(strip_tags($comment)), 0, 500) ?: null), 'created_at' => now(),
        ]);
    }

    /** The project is over (completed, or ended after it started) and the days to answer are not. */
    public function open(Agreement $a): bool
    {
        $ended = $this->endedAt($a);

        return $ended !== null && $ended->copy()->addDays(self::OPEN_DAYS)->isFuture();
    }

    private function endedAt(Agreement $a): ?Carbon
    {
        if ($a->status === Agreement::COMPLETED) {
            return $a->completed_at;
        }
        if ($a->status === Agreement::CANCELLED && $a->activated_at !== null) {
            return $a->updated_at;
        }

        return null;
    }

    /**
     * For staff: how satisfied people are, and why not, in a period.
     *
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('freelance_satisfaction_surveys')->whereBetween('created_at', [$from, $to]);
        $all = (clone $rows)->get(['side', 'score', 'reasons']);
        $bySide = fn (string $side) => [
            'count' => $all->where('side', $side)->count(),
            'average' => $all->where('side', $side)->isEmpty() ? null : round((float) $all->where('side', $side)->avg('score'), 2),
        ];
        $reasons = [];
        foreach ($all->where('score', '<=', self::NOT_SATISFIED_MAX) as $r) {
            foreach (json_decode((string) $r->reasons, true) ?: [] as $code) {
                $reasons[$r->side][$code] = ($reasons[$r->side][$code] ?? 0) + 1;
            }
        }

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'total' => $all->count(),
            'average' => $all->isEmpty() ? null : round((float) $all->avg('score'), 2),
            'not_satisfied' => $all->where('score', '<=', self::NOT_SATISFIED_MAX)->count(),
            'by_side' => ['client' => $bySide('client'), 'freelancer' => $bySide('freelancer')],
            'distribution' => collect(range(1, 5))->map(fn (int $n) => ['score' => $n, 'count' => $all->where('score', $n)->count()])->all(),
            'reasons' => array_map(fn (string $code) => ['key' => $code, 'client' => $reasons['client'][$code] ?? 0, 'freelancer' => $reasons['freelancer'][$code] ?? 0], self::REASONS),
            'latest' => DB::table('freelance_satisfaction_surveys as s')->join('freelance_projects as p', 'p.id', '=', 's.project_id')
                ->whereBetween('s.created_at', [$from, $to])->where('s.score', '<=', self::NOT_SATISFIED_MAX)->orderByDesc('s.id')->limit(30)
                ->get(['s.side', 's.score', 's.reasons', 's.comment', 'p.title as project', 's.created_at'])
                ->map(fn ($r) => [
                    'side' => $r->side, 'score' => (int) $r->score, 'reasons' => json_decode((string) $r->reasons, true) ?: [], 'comment' => $r->comment,
                    'project' => $r->project, 'at' => Carbon::parse($r->created_at)->toIso8601String(),
                ])->all(),
        ];
    }
}
