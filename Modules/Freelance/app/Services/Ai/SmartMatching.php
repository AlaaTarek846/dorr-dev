<?php

namespace Modules\Freelance\Services\Ai;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceProjectInvitation as Invitation;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Projects\FreelanceReviews;
use Modules\Freelance\Services\Projects\ProjectInvitations;
use Modules\Freelance\Services\Skills\FreelanceSkillBadges;
use Modules\User\Models\User;

/**
 * Smart Matching (FS-501 / FS-502 / FS-503, light): the best freelancers and teams for a client's project, each with a score out of
 * 100 and the reasons in words — the client sees why, never a black box. The score is plain, explainable rules on what DORR knows:
 *
 *  - skills (30): how many of the project's skills they have;            - specialty (15): the project's own, or its parent;
 *  - quality (20): the clients' rating (a newcomer gets the middle, so new people are not buried — FS-504);
 *  - experience (10): finished projects like this one;                   - reliability (10): parts delivered on time;
 *  - availability (10): taking work now and active lately;               - budget (5): their guide price meets the budget.
 *
 * A paid freelancer plan does not move anyone up here (FS-504): the order is the score only. Then DORR AI (on the client's DORR AI
 * plan) reads the best ones — without names — and writes one line on why each fits and a short recommendation. Lists: the best match,
 * the best quality, the best value, the fastest available, and teams. The answer is kept for a while per project version, so opening
 * the page again costs no new AI call.
 */
class SmartMatching
{
    public const LISTS = ['best', 'quality', 'value', 'fastest'];

    private const POOL = 30;

    private const SHOWN = 10;

    private const EXPLAINED = 6;

    public function __construct(
        private readonly FreelanceAi $ai,
        private readonly FreelanceReviews $reviews,
        private readonly FreelanceSkillBadges $badges,
        private readonly FreelanceFeatures $features,
        private readonly ProjectInvitations $invitations,
    ) {}

    /** @return array<string, mixed> */
    public function for(Project $project, User $client, bool $fresh = false): array
    {
        $this->ai->refuseUnlessEntitled($client);
        $project->loadMissing(['specialty', 'skills.translations']);
        $key = 'freelance:matches:'.$project->id.':'.$project->updated_at?->timestamp.':'.app()->getLocale();
        if ($fresh) {
            Cache::forget($key);
        }
        $result = Cache::remember($key, now()->addMinutes(30), fn () => $this->compute($project, $client));

        // What changes without the project changing: who was invited or offered meanwhile, and whether inviting is possible now.
        $invited = Invitation::query()->where('project_id', $project->id)->whereIn('status', Invitation::LIVE)->pluck('freelancer_id')->all();
        $offered = FreelanceOffer::query()->where('project_id', $project->id)->where('status', '!=', 'withdrawn')->pluck('freelancer_id')->all();
        $result['candidates'] = array_map(fn (array $c) => $c + [
            'invited' => in_array($c['_id'], $invited, true),
            'offered' => in_array($c['_id'], $offered, true),
        ], $result['candidates']);
        $result['candidates'] = array_map(fn (array $c) => array_diff_key($c, ['_id' => 1]), $result['candidates']);
        $result['can_invite'] = (bool) ($this->invitations->summary($project)['can_invite'] ?? false);

        return $result;
    }

    /** @return array<string, mixed> */
    private function compute(Project $project, User $client): array
    {
        $skillIds = $project->skills->pluck('id')->map(fn ($i) => (int) $i)->all();
        $specialtyId = $project->freelance_specialty_id === null ? null : (int) $project->freelance_specialty_id;
        $parentId = $project->specialty?->parent_id === null ? null : (int) $project->specialty->parent_id;
        if ($specialtyId === null && $skillIds === []) {
            return ['candidates' => [], 'lists' => array_fill_keys(self::LISTS, []), 'teams' => [], 'summary' => null, 'needs' => 'specialty'];
        }

        $people = $this->people($project, $skillIds, $specialtyId, $parentId);
        $teams = $this->teams($project, $skillIds, $specialtyId, $parentId);

        $best = collect($people)->sortByDesc('score')->values();
        $lists = [
            'best' => $best->take(self::SHOWN)->pluck('uuid')->all(),
            'quality' => collect($people)->filter(fn ($c) => $c['rating'] !== null || $c['completed_projects'] > 0)
                ->sortBy([['rating', 'desc'], ['reviews_count', 'desc'], ['completed_projects', 'desc']])->take(self::SHOWN)->pluck('uuid')->values()->all(),
            'value' => collect($people)->filter(fn ($c) => $c['score'] >= 45)
                ->sortBy(fn ($c) => [$c['rate']['min_minor'] ?? PHP_INT_MAX, -$c['score']])->take(self::SHOWN)->pluck('uuid')->values()->all(),
            'fastest' => collect($people)->filter(fn ($c) => $c['available_now'])
                ->sortBy(fn ($c) => [-($c['_active'] ?? 0), -$c['score']])->take(self::SHOWN)->pluck('uuid')->values()->all(),
        ];

        $explained = $this->explain($project, $client, $best->take(self::EXPLAINED)->all(), array_slice($teams, 0, 3));
        $people = array_map(function (array $c) use ($explained) {
            $c['why'] = $explained['why']['F'.$c['_ref']] ?? null;

            return array_diff_key($c, ['_ref' => 1, '_active' => 1]);
        }, $best->all());
        $teams = array_map(function (array $t) use ($explained) {
            $t['why'] = $explained['why']['T'.$t['_ref']] ?? null;

            return array_diff_key($t, ['_ref' => 1]);
        }, $teams);

        return ['candidates' => $people, 'lists' => $lists, 'teams' => $teams, 'summary' => $explained['summary'], 'needs' => null];
    }

    /** @return list<array<string, mixed>> */
    private function people(Project $project, array $skillIds, ?int $specialtyId, ?int $parentId): array
    {
        $specialties = array_values(array_filter([$specialtyId, $parentId]));
        $today = now()->toDateString();
        $found = Freelancer::query()
            ->where('status', FreelancerStatus::Verified->value)
            ->whereIn('country_id', $this->features->openCountries())
            ->where(fn ($q) => $q
                ->when($specialties !== [], fn ($w) => $w->orWhereHas('specialties', fn ($s) => $s->whereIn('freelance_specialties.id', $specialties)))
                ->when($skillIds !== [], fn ($w) => $w->orWhereHas('skills', fn ($s) => $s->whereIn('freelance_skills.id', $skillIds))))
            ->withCount(['skills as overlap' => fn ($q) => $q->whereIn('freelance_skills.id', $skillIds === [] ? [0] : $skillIds)])
            ->with(['specialties.translations', 'skills.translations', 'availability', 'timeOff' => fn ($t) => $t->whereDate('ends_on', '>=', $today)])
            ->orderByDesc('overlap')->orderByDesc('last_active_at')->orderBy('id')
            ->limit(self::POOL)->get();

        $ref = 0;

        return $found->map(function (Freelancer $f) use ($project, $skillIds, $specialtyId, $parentId, $today, &$ref) {
            $stats = $this->reviews->stats($f, $specialtyId);
            $mySpecialties = $f->specialties->pluck('id')->map(fn ($i) => (int) $i)->all();
            $matched = $f->skills->filter(fn ($s) => in_array((int) $s->id, $skillIds, true));
            $a = $f->availability;
            $off = $f->timeOff->contains(fn ($t) => $t->starts_on?->toDateString() <= $today && $t->ends_on?->toDateString() >= $today);
            $accepting = ! $off && ($a === null || $a->accepting_requests || ($a->paused_until !== null && $a->paused_until->toDateString() < $today));
            $activeDays = $f->last_active_at === null ? null : (int) $f->last_active_at->diffInDays(now());
            $budgetFit = $this->budgetFit($project, $a);
            $badges = collect($this->badges->of($f, 50))->filter(fn ($b) => in_array($b['skill_id'], $skillIds, true))->values();

            $isNew = $stats['reviews_count'] === 0 && $stats['completed_projects'] === 0;
            $parts = [
                'skills' => $skillIds === [] ? 15 : 30 * ($matched->count() / count($skillIds)),
                'specialty' => $specialtyId !== null && in_array($specialtyId, $mySpecialties, true) ? 15 : ($parentId !== null && in_array($parentId, $mySpecialties, true) ? 8 : 0),
                'quality' => $stats['rating'] !== null ? max(0, min(20, 20 * ((float) $stats['rating'] - 3) / 2)) : 10,
                'experience' => ($stats['similar_projects'] ?? 0) > 0 ? 10 * min(3, (int) $stats['similar_projects']) / 3 : 5 * min(5, (int) $stats['completed_projects']) / 5,
                'reliability' => $stats['on_time_rate'] !== null ? 10 * $stats['on_time_rate'] / 100 : 6,
                'availability' => ! $accepting ? 0 : ($activeDays !== null && $activeDays <= 14 ? 10 : 6),
                'budget' => match ($budgetFit) { true => 5, null => 3, false => 0 },
            ];
            $score = (int) round(min(100, array_sum($parts) + min(5, 2.5 * $badges->count())));

            $reasons = [];
            if ($skillIds !== [] && $matched->isNotEmpty()) {
                $reasons[] = __('freelance.ai.reason.skills', ['n' => $matched->count(), 'of' => count($skillIds)]);
            }
            if ($parts['specialty'] === 15) {
                $reasons[] = __('freelance.ai.reason.specialty');
            }
            if ($stats['rating'] !== null) {
                $reasons[] = __('freelance.ai.reason.rating', ['rating' => $stats['rating'], 'count' => $stats['reviews_count']]);
            }
            if (($stats['similar_projects'] ?? 0) > 0) {
                $reasons[] = __('freelance.ai.reason.similar', ['n' => $stats['similar_projects']]);
            } elseif ($stats['completed_projects'] > 0) {
                $reasons[] = __('freelance.ai.reason.completed', ['n' => $stats['completed_projects']]);
            }
            if ($stats['on_time_rate'] !== null && $stats['on_time_rate'] >= 80) {
                $reasons[] = __('freelance.ai.reason.on_time', ['pct' => $stats['on_time_rate']]);
            }
            foreach ($badges->take(2) as $b) {
                $reasons[] = __('freelance.ai.reason.badge', ['skill' => $b['name']]);
            }
            if ($accepting && $activeDays !== null && $activeDays <= 3) {
                $reasons[] = __('freelance.ai.reason.available');
            }
            if ($budgetFit === true) {
                $reasons[] = __('freelance.ai.reason.budget');
            }
            if ($isNew) {
                $reasons[] = __('freelance.ai.reason.new');
            }

            return [
                '_ref' => ++$ref,
                '_id' => (int) $f->id,
                '_active' => $f->last_active_at?->timestamp ?? 0,
                'uuid' => $f->uuid,
                'name' => $f->name,
                'headline' => $f->headline,
                'avatar_url' => $f->getFirstMediaUrl('avatar') ?: null,
                'specialties' => $f->specialties->map(fn ($s) => $s->translatedName())->filter()->values()->all(),
                'skills_matched' => $matched->map(fn ($s) => $s->translatedName())->filter()->values()->all(),
                'score' => $score,
                'rating' => $stats['rating'],
                'reviews_count' => $stats['reviews_count'],
                'completed_projects' => $stats['completed_projects'],
                'similar_projects' => $stats['similar_projects'],
                'on_time_rate' => $stats['on_time_rate'],
                'available_now' => $accepting,
                'years_of_experience' => $f->years_of_experience,
                'rate' => $a === null || $a->rate_type === null ? null : ['type' => $a->rate_type, 'min_minor' => $a->rate_min_minor, 'max_minor' => $a->rate_max_minor],
                'is_new' => $isNew,
                'reasons' => $reasons,
            ];
        })->all();
    }

    /** Teams whose active members work in the project's specialty (or have its skills) — when teams are open. @return list<array<string, mixed>> */
    private function teams(Project $project, array $skillIds, ?int $specialtyId, ?int $parentId): array
    {
        $countries = $this->features->openCountries('teams');
        if ($countries === []) {
            return [];
        }
        $specialties = array_values(array_filter([$specialtyId, $parentId]));
        $memberFits = fn ($f) => $f->where(fn ($q) => $q
            ->when($specialties !== [], fn ($w) => $w->orWhereHas('specialties', fn ($s) => $s->whereIn('freelance_specialties.id', $specialties)))
            ->when($skillIds !== [], fn ($w) => $w->orWhereHas('skills', fn ($s) => $s->whereIn('freelance_skills.id', $skillIds))));

        $teams = FreelanceTeam::query()->where('status', FreelanceTeam::ACTIVE)->where('visibility', FreelanceTeam::PUBLIC)->where('accepting_projects', true)
            ->whereIn('country_id', $countries)
            ->whereHas('members', fn ($m) => $m->where('status', FreelanceTeamMember::ACTIVE)->whereHas('freelancer', $memberFits))
            ->with(['members' => fn ($m) => $m->where('status', FreelanceTeamMember::ACTIVE)->with('freelancer.skills', 'freelancer.specialties')])
            ->limit(15)->get();

        $ref = 0;

        return $teams->map(function (FreelanceTeam $team) use ($skillIds, $specialtyId, $parentId, &$ref) {
            $freelancers = $team->members->pluck('freelancer')->filter();
            $covered = $freelancers->flatMap(fn ($f) => $f->skills->pluck('id'))->map(fn ($i) => (int) $i)->unique()->intersect($skillIds);
            $specs = $freelancers->flatMap(fn ($f) => $f->specialties->pluck('id'))->map(fn ($i) => (int) $i)->unique()->all();
            $stats = $this->reviews->teamStats($team);
            $score = (int) round(min(100,
                ($skillIds === [] ? 20 : 40 * $covered->count() / count($skillIds))
                + ($specialtyId !== null && in_array($specialtyId, $specs, true) ? 20 : ($parentId !== null && in_array($parentId, $specs, true) ? 10 : 0))
                + ($stats['rating'] !== null ? max(0, min(20, 20 * ((float) $stats['rating'] - 3) / 2)) : 10)
                + 5 * min(4, (int) $stats['completed_projects']) / 4
                + ($team->verified_at !== null ? 10 : 0)
                + min(5, $freelancers->count()),
            ));
            $reasons = array_values(array_filter([
                $skillIds !== [] && $covered->isNotEmpty() ? __('freelance.ai.reason.team_skills', ['n' => $covered->count(), 'of' => count($skillIds)]) : null,
                __('freelance.ai.reason.team_size', ['n' => $freelancers->count()]),
                $stats['rating'] !== null ? __('freelance.ai.reason.rating', ['rating' => $stats['rating'], 'count' => $stats['reviews_count']]) : null,
                $team->verified_at !== null ? __('freelance.ai.reason.team_verified') : null,
            ]));

            return [
                '_ref' => ++$ref,
                'uuid' => $team->uuid,
                'name' => $team->name,
                'logo_url' => $team->logoUrl(),
                'members_count' => $freelancers->count(),
                'verified' => $team->verified_at !== null,
                'score' => $score,
                'rating' => $stats['rating'],
                'reviews_count' => $stats['reviews_count'],
                'completed_projects' => $stats['completed_projects'],
                'reasons' => $reasons,
            ];
        })->sortByDesc('score')->take(5)->values()->all();
    }

    /** null: no guide price to compare; true / false: their range meets the project's budget or not. */
    private function budgetFit(Project $project, $availability): ?bool
    {
        if ($availability === null || $availability->rate_type !== 'fixed' || ($availability->rate_min_minor === null && $availability->rate_max_minor === null) || $project->budget_min_minor === null) {
            return null;
        }
        $low = (int) $project->budget_min_minor;
        $high = $project->budget_type === 'range' ? (int) ($project->budget_max_minor ?? $low) : $low;

        return ! (($availability->rate_max_minor !== null && $availability->rate_max_minor < $low) || ($availability->rate_min_minor !== null && $availability->rate_min_minor > $high));
    }

    /**
     * DORR AI's words on the best ones, without names: one line each and a short recommendation. Charged to the client's plan.
     *
     * @param  list<array<string, mixed>>  $people
     * @param  list<array<string, mixed>>  $teams
     * @return array{summary: ?string, why: array<string, string>}
     */
    private function explain(Project $project, User $client, array $people, array $teams): array
    {
        if ($people === [] && $teams === []) {
            return ['summary' => null, 'why' => []];
        }
        $locale = app()->getLocale() === 'ar' ? 'Arabic' : 'English';
        $lines = ['Project: '.FreelanceAi::scrub((string) $project->title), 'About: '.FreelanceAi::scrub(Str::limit((string) $project->description, 1200)),
            'Skills wanted: '.$project->skills->map(fn ($s) => $s->translatedName())->implode(', '),
            'Budget: '.($project->budget_min_minor ?? '-').($project->budget_type === 'range' ? '-'.($project->budget_max_minor ?? '') : '').' (minor units), duration: '.($project->duration_days ?? '-').' days', '', 'Candidates:'];
        foreach ($people as $c) {
            $lines[] = 'F'.$c['_ref'].': score '.$c['score'].'; '.Str::limit((string) $c['headline'], 80).'; skills matched: '.implode(', ', $c['skills_matched'])
                .'; rating: '.($c['rating'] ?? 'new').' ('.$c['reviews_count'].'); done: '.$c['completed_projects'].'; similar: '.($c['similar_projects'] ?? 0)
                .'; on time: '.($c['on_time_rate'] === null ? '-' : $c['on_time_rate'].'%').'; available: '.($c['available_now'] ? 'yes' : 'no').'; years: '.($c['years_of_experience'] ?? '-');
        }
        foreach ($teams as $t) {
            $lines[] = 'T'.$t['_ref'].': a team of '.$t['members_count'].'; score '.$t['score'].'; rating: '.($t['rating'] ?? 'new').'; done: '.$t['completed_projects'].'; verified: '.($t['verified'] ? 'yes' : 'no');
        }
        $system = <<<PROMPT
You are DORR AI helping a client on DORR, a freelance marketplace, pick who to work with. You get the project and the best candidates by
DORR's score (F = a freelancer, T = a team), without names. Write in {$locale}, plain and honest, no emojis, no markdown. Only use the facts
given; never promise results. For each candidate one short sentence (max 20 words) on why it fits or what to check. Then "summary": two
short sentences recommending who to start with and why (refer to them as "the first", "the team"... never by code).
Answer with JSON only: {"why": {"F1": "...", "T1": "..."}, "summary": "..."}
PROMPT;
        $out = $this->ai->ask($client, $system, implode("\n", $lines), 'freelance.smart_matching');
        $why = collect(is_array($out['why'] ?? null) ? $out['why'] : [])
            ->filter(fn ($v, $k) => is_string($v) && preg_match('/^[FT]\d+$/', (string) $k))
            ->map(fn ($v) => Str::limit(trim(strip_tags($v)), 240, ''))->all();

        return ['summary' => is_string($out['summary'] ?? null) ? Str::limit(trim(strip_tags($out['summary'])), 500, '') : null, 'why' => $why];
    }
}
