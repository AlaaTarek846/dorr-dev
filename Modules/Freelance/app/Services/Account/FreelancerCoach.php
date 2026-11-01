<?php

namespace Modules\Freelance\Services\Account;

use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Models\FreelanceService;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;

/**
 * «Improve my profile» (FL-L11): what to do next to be found and chosen more, ranked by what it is worth. It is not an AI — it reads
 * the profile and says it back in fixed rules: every step the completeness bar still misses (worth its weight in points), then a few
 * things the bar does not measure (more skills and works, being verified, the first ready-made service). The same for every plan.
 *
 * Each tip is `{key, impact, points, action}`: `impact` is high / medium / low, `points` what the bar gains when it is done (0 for the
 * ones the bar does not count), and `action` the screen the app opens (`profile`, `skills`, `specialties`, `portfolio`, `verification`,
 * `services`). The apps write the sentence; the server only decides what is worth saying.
 */
class FreelancerCoach
{
    /** Skills and works below these are enough for the bar but not for a client choosing between people. */
    public const SKILLS_GOOD = 6;

    public const PORTFOLIO_GOOD = 3;

    /** step of the completeness bar => the screen that fixes it */
    private const ACTION_OF = [
        'name' => 'profile', 'avatar' => 'profile', 'headline' => 'profile', 'bio' => 'profile', 'email' => 'profile',
        'languages' => 'profile', 'experience' => 'profile', 'work' => 'profile',
        'specialties' => 'specialties', 'skills' => 'skills', 'portfolio' => 'portfolio',
    ];

    public function __construct(private readonly FreelancerCompleteness $completeness, private readonly FreelanceFeatures $features) {}

    /** @return array{percent: int, level: string, done: int, total: int, tips: list<array{key: string, impact: string, points: int, action: string}>} */
    public function for(Freelancer $f): array
    {
        $c = $this->completeness->of($f);
        $weights = FreelancerCompleteness::weights();

        $tips = [];
        foreach ($c['missing'] as $step) {
            $tips[] = ['key' => $step, 'points' => $weights[$step], 'action' => self::ACTION_OF[$step]];
        }

        $skills = $f->skills()->count();
        if ($skills >= FreelancerCompleteness::SKILLS_MIN && $skills < self::SKILLS_GOOD) {
            $tips[] = ['key' => 'more_skills', 'points' => 0, 'action' => 'skills'];
        }
        $works = $f->portfolioItems()->count();
        if ($works >= 1 && $works < self::PORTFOLIO_GOOD) {
            $tips[] = ['key' => 'more_portfolio', 'points' => 0, 'action' => 'portfolio'];
        }
        if ($f->status !== FreelancerStatus::Verified) {
            $tips[] = ['key' => 'verification', 'points' => 0, 'action' => 'verification'];
        } elseif ($f->country_id !== null && $this->features->allows((int) $f->country_id, 'services') && ! $f->services()->where('status', FreelanceService::PUBLISHED)->exists()) {
            $tips[] = ['key' => 'first_service', 'points' => 0, 'action' => 'services'];
        }

        $tips = collect($tips)->map(fn (array $t) => $t + ['impact' => $this->impact($t)])
            ->sortBy([fn ($a, $b) => $this->rank($b['impact']) <=> $this->rank($a['impact']), fn ($a, $b) => $b['points'] <=> $a['points']])
            ->values()->all();

        return [
            'percent' => $c['percent'],
            'level' => $c['percent'] >= 100 ? ($tips === [] ? 'great' : 'good') : ($c['percent'] >= 70 ? 'good' : 'start'),
            'done' => count(array_filter($c['steps'])),
            'total' => count($c['steps']),
            'tips' => $tips,
        ];
    }

    /** @param  array{key: string, points: int}  $tip */
    private function impact(array $tip): string
    {
        return match (true) {
            $tip['key'] === 'verification', $tip['points'] >= 10 => 'high',
            $tip['points'] >= 5, $tip['key'] === 'first_service' => 'medium',
            default => 'low',
        };
    }

    private function rank(string $impact): int
    {
        return ['high' => 3, 'medium' => 2, 'low' => 1][$impact] ?? 0;
    }
}
