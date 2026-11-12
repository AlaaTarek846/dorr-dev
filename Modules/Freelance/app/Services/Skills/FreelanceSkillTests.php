<?php

namespace Modules\Freelance\Services\Skills;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSkillAttempt as Attempt;
use Modules\Freelance\Models\FreelanceSkillQuestion as Question;
use Modules\Freelance\Models\FreelanceSkillTest as Test;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerSkillBadge;
use Modules\Freelance\Services\Features\FreelanceFeatures;

/**
 * Skill tests (FL-L08). A freelancer takes the test of a skill that is on their profile; the server draws the questions (and shuffles each
 * one's options), freezes them in the attempt, and marks the answers when they come back. The right answers never leave the server: what the
 * app gets is the texts, and what it sends is the position of the option shown. A pass earns the verified badge of that skill, which ends
 * after `freelance.skill_tests.badge_valid_days` (0 = never); a fail waits `retake_hours` before the next go.
 *
 * FS-602 (owner 2026-11-12): a badge has a level. The test gives beginner (a pass) or intermediate (`intermediate_percent` or more); advanced and
 * expert come from a practical challenge (`FreelanceSkillChallenges`). From `renew_days` before a badge ends the test may be sat again to renew
 * it, and a renewal keeps a level a challenge earned.
 */
class FreelanceSkillTests
{
    /** The fewest questions a test can have — fewer would be marked by luck. */
    public const MIN_QUESTIONS = 3;

    /** Seconds past the deadline that a submit is still taken (a slow network must not fail a finished test). */
    private const GRACE = 30;

    public function __construct(private readonly FreelanceFeatures $features, private readonly FreelanceSkillBadges $badges) {}

    public function enabled(Freelancer $f): bool
    {
        return $f->country_id !== null && $this->features->allows((int) $f->country_id, 'skill_tests');
    }

    /**
     * What the admin sets for a skill, with the defaults of the config where it has no row yet.
     *
     * @return array{status: bool, questions_count: int, minutes: int, pass_percent: int, retake_hours: int, intermediate_percent: int, challenge_enabled: bool, challenge_brief: ?array}
     */
    public static function settings(?Test $t): array
    {
        $d = (array) config('freelance.skill_tests.defaults');

        return [
            'status' => (bool) ($t?->status ?? false),
            'questions_count' => (int) ($t->questions_count ?? $d['questions_count'] ?? 10),
            'minutes' => (int) ($t->minutes ?? $d['minutes'] ?? 15),
            'pass_percent' => (int) ($t->pass_percent ?? $d['pass_percent'] ?? 70),
            'retake_hours' => (int) ($t->retake_hours ?? $d['retake_hours'] ?? 24),
            'intermediate_percent' => (int) ($t->intermediate_percent ?? $d['intermediate_percent'] ?? 85),
            'challenge_enabled' => (bool) ($t?->challenge_enabled ?? false),
            'challenge_brief' => $t?->challenge_brief,
        ];
    }

    /**
     * The freelancer's skills that have a test on, each with where they stand: the badge they hold, a test in progress, or when they may try again.
     *
     * @return array<string, mixed>
     */
    public function overview(Freelancer $f): array
    {
        $on = $this->enabled($f);
        $skills = FreelanceSkill::query()->with('translations')
            ->join('freelancer_skills', 'freelancer_skills.freelance_skill_id', '=', 'freelance_skills.id')
            ->where('freelancer_skills.freelancer_id', $f->id)->where('freelance_skills.status', FreelanceSkill::ACTIVE)
            ->get(['freelance_skills.*']);
        $tests = Test::query()->whereIn('freelance_skill_id', $skills->pluck('id'))->where('status', true)->get()->keyBy('freelance_skill_id');
        $bank = Question::query()->whereIn('freelance_skill_id', $tests->keys())->where('status', true)->selectRaw('freelance_skill_id, count(*) as n')->groupBy('freelance_skill_id')->pluck('n', 'freelance_skill_id');

        $rows = [];
        foreach ($skills as $skill) {
            $test = $tests->get($skill->id);
            if ($test === null || (int) ($bank[$skill->id] ?? 0) < self::MIN_QUESTIONS) {
                continue;
            }
            $s = self::settings($test);
            $badge = $this->badges->held($f, $skill->id);
            $renewing = $badge !== null && $this->renewable($badge);
            $wait = $this->waitUntil($f, $skill->id, $s['retake_hours']);
            $open = Attempt::query()->where('freelancer_id', $f->id)->where('freelance_skill_id', $skill->id)->where('status', Attempt::STARTED)->where('expires_at', '>', now())->exists();
            $rows[] = [
                'skill_id' => $skill->id,
                'name' => $skill->translatedName(),
                'questions' => min($s['questions_count'], (int) $bank[$skill->id]),
                'minutes' => $s['minutes'],
                'pass_percent' => $s['pass_percent'],
                'intermediate_percent' => $s['intermediate_percent'],
                'badge' => $badge === null ? null : [
                    'score_percent' => $badge->score_percent, 'level' => $badge->level,
                    'earned_at' => $badge->earned_at?->toIso8601String(), 'expires_at' => $badge->expires_at?->toIso8601String(),
                ],
                // FS-602: the badge may be renewed by sitting the test again (it keeps a level a challenge earned).
                'renewable' => $renewing,
                'in_progress' => $open,
                'retake_at' => $wait?->toIso8601String(),
                'can_start' => $on && ($badge === null || $renewing) && $wait === null,
                'challenge' => app(FreelanceSkillChallenges::class)->stateFor($f, $skill->id, $test, $badge),
            ];
        }

        return ['enabled' => $on, 'tests' => $rows];
    }

    /** @return array<string, mixed> the attempt with its questions — texts only, in the order and option order of the draw */
    public function start(Freelancer $f, int $skillId): array
    {
        if (! $this->enabled($f)) {
            throw new FreelanceException('skill_test_closed', 403);
        }
        $skill = FreelanceSkill::query()->where('status', FreelanceSkill::ACTIVE)->find($skillId) ?? throw new FreelanceException('skill_test_off', 404);
        if (! DB::table('freelancer_skills')->where('freelancer_id', $f->id)->where('freelance_skill_id', $skill->id)->exists()) {
            throw new FreelanceException('skill_test_not_yours', 422);
        }
        $test = Test::query()->where('freelance_skill_id', $skill->id)->where('status', true)->first() ?? throw new FreelanceException('skill_test_off', 404);
        $s = self::settings($test);

        return DB::transaction(function () use ($f, $skill, $s) {
            // One test at a time per skill: asking twice gets the same attempt back (the phone may have restarted meanwhile).
            $open = Attempt::query()->where('freelancer_id', $f->id)->where('freelance_skill_id', $skill->id)->where('status', Attempt::STARTED)->lockForUpdate()->first();
            if ($open !== null) {
                if ($open->expires_at->isFuture()) {
                    return $this->present($open);
                }
                $open->update(['status' => Attempt::EXPIRED, 'submitted_at' => $open->expires_at]);
            }
            $held = $this->badges->held($f, $skill->id);
            if ($held !== null && ! $this->renewable($held)) {
                throw new FreelanceException('skill_test_held', 422);
            }
            if (($wait = $this->waitUntil($f, $skill->id, $s['retake_hours'])) !== null) {
                throw new FreelanceException('skill_test_wait', 422, ['h' => max(1, (int) ceil(now()->diffInMinutes($wait, false) / 60))]);
            }

            $bank = Question::query()->where('freelance_skill_id', $skill->id)->where('status', true)->get();
            if ($bank->count() < self::MIN_QUESTIONS) {
                throw new FreelanceException('skill_test_few', 422);
            }
            $drawn = $bank->shuffle()->take(min($s['questions_count'], $bank->count()))->map(function (Question $q) {
                $order = array_keys((array) $q->options);
                shuffle($order);

                return ['id' => $q->id, 'options' => $order];
            })->values()->all();

            return $this->present(Attempt::query()->create([
                'freelancer_id' => $f->id, 'freelance_skill_id' => $skill->id, 'questions' => $drawn, 'status' => Attempt::STARTED,
                'pass_percent' => $s['pass_percent'], 'started_at' => now(), 'expires_at' => now()->addMinutes($s['minutes']),
            ]));
        });
    }

    /**
     * Marks an attempt. `$answers` maps a question id to the position of the option picked among those shown; a question left out is wrong.
     * Submitting twice returns the first result.
     *
     * @param  array<int|string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function submit(Freelancer $f, int $attemptId, array $answers): array
    {
        return DB::transaction(function () use ($f, $attemptId, $answers) {
            $a = Attempt::query()->where('freelancer_id', $f->id)->lockForUpdate()->find($attemptId) ?? throw new FreelanceException('skill_test_gone', 404);
            if ($a->status !== Attempt::STARTED) {
                return $this->result($a);
            }
            if ($a->expires_at->copy()->addSeconds(self::GRACE)->isPast()) {
                $a->update(['status' => Attempt::EXPIRED, 'submitted_at' => now(), 'score_percent' => 0, 'correct_count' => 0]);

                return $this->result($a);
            }

            $questions = Question::query()->whereIn('id', collect($a->questions)->pluck('id'))->get()->keyBy('id');
            $correct = 0;
            foreach ($a->questions as $row) {
                $picked = $answers[$row['id']] ?? $answers[(string) $row['id']] ?? null;
                if (! is_int($picked) && ! (is_string($picked) && ctype_digit($picked))) {
                    continue;
                }
                $original = $row['options'][(int) $picked] ?? null;
                $q = $questions->get($row['id']);
                if ($original !== null && $q !== null && (bool) ($q->options[$original]['correct'] ?? false)) {
                    $correct++;
                }
            }
            $total = max(1, count($a->questions));
            $percent = (int) floor($correct * 100 / $total);
            $passed = $percent >= $a->pass_percent;
            $a->update(['status' => $passed ? Attempt::PASSED : Attempt::FAILED, 'score_percent' => $percent, 'correct_count' => $correct, 'submitted_at' => now()]);

            if ($passed) {
                $days = (int) config('freelance.skill_tests.badge_valid_days', 365);
                $s = self::settings(Test::query()->where('freelance_skill_id', $a->freelance_skill_id)->first());
                $level = $percent >= $s['intermediate_percent'] ? FreelancerSkillBadge::INTERMEDIATE : FreelancerSkillBadge::BEGINNER;
                // FS-602: renewing a badge still in force keeps the level a challenge earned (a revoked or ended one starts again from the score).
                $before = FreelancerSkillBadge::query()->where('freelancer_id', $f->id)->where('freelance_skill_id', $a->freelance_skill_id)->first();
                if ($before !== null && $before->isValid() && FreelancerSkillBadge::rank($before->level) > FreelancerSkillBadge::rank($level)) {
                    $level = $before->level;
                }
                FreelancerSkillBadge::query()->updateOrCreate(
                    ['freelancer_id' => $f->id, 'freelance_skill_id' => $a->freelance_skill_id],
                    ['attempt_id' => $a->id, 'score_percent' => $percent, 'level' => $level, 'earned_at' => now(), 'expires_at' => $days > 0 ? now()->addDays($days) : null, 'revoked_at' => null, 'revoked_reason' => null],
                );
            }

            return $this->result($a);
        });
    }

    /** The admin takes a badge back (a cheat, a wrong question). The freelancer may sit the test again at once. */
    public function revoke(FreelancerSkillBadge $badge, ?string $reason): void
    {
        $badge->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
        Attempt::query()->where('freelancer_id', $badge->freelancer_id)->where('freelance_skill_id', $badge->freelance_skill_id)->whereIn('status', [Attempt::FAILED, Attempt::EXPIRED])
            ->update(['submitted_at' => now()->subYears(10)]);
    }

    /** @return array<string, mixed> */
    private function present(Attempt $a): array
    {
        $locale = app()->getLocale();
        $bank = Question::query()->whereIn('id', collect($a->questions)->pluck('id'))->get()->keyBy('id');

        return [
            'attempt_id' => $a->id,
            'skill_id' => $a->freelance_skill_id,
            'expires_at' => $a->expires_at->toIso8601String(),
            'seconds_left' => max(0, (int) now()->diffInSeconds($a->expires_at, false)),
            'pass_percent' => $a->pass_percent,
            'questions' => collect($a->questions)->map(fn (array $row) => [
                'id' => $row['id'],
                'prompt' => Question::pick($bank->get($row['id'])?->prompt, $locale),
                'options' => array_map(fn (int $i) => Question::pick($bank->get($row['id'])?->options[$i]['text'] ?? null, $locale), $row['options']),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function result(Attempt $a): array
    {
        $s = self::settings(Test::query()->where('freelance_skill_id', $a->freelance_skill_id)->first());
        $badge = $a->status === Attempt::PASSED ? $this->badges->held(Freelancer::query()->findOrFail($a->freelancer_id), $a->freelance_skill_id) : null;

        return [
            'attempt_id' => $a->id,
            'skill_id' => $a->freelance_skill_id,
            'status' => $a->status,
            'passed' => $a->status === Attempt::PASSED,
            'score_percent' => (int) $a->score_percent,
            'correct_count' => (int) $a->correct_count,
            'total' => count($a->questions),
            'pass_percent' => $a->pass_percent,
            'badge' => $badge === null ? null : ['score_percent' => $badge->score_percent, 'level' => $badge->level, 'expires_at' => $badge->expires_at?->toIso8601String()],
            'retake_at' => $a->status === Attempt::PASSED ? null : $a->submitted_at?->copy()->addHours($s['retake_hours'])->toIso8601String(),
        ];
    }

    /** FS-602: a badge that ends within `renew_days` may be renewed by sitting the test again. */
    private function renewable(FreelancerSkillBadge $badge): bool
    {
        return $badge->expires_at !== null && $badge->expires_at->lte(now()->addDays((int) config('freelance.skill_tests.renew_days', 30)));
    }

    /** When the freelancer may try again after a failed or timed-out go, or null if they may now. */
    private function waitUntil(Freelancer $f, int $skillId, int $hours): ?Carbon
    {
        $last = Attempt::query()->where('freelancer_id', $f->id)->where('freelance_skill_id', $skillId)->whereIn('status', [Attempt::FAILED, Attempt::EXPIRED])->latest('submitted_at')->first();
        $until = $last?->submitted_at?->copy()->addHours($hours);

        return $until !== null && $until->isFuture() ? $until : null;
    }
}
