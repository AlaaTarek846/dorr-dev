<?php

namespace Modules\Freelance\Services\Account;

use Modules\Freelance\Models\Freelancer;

/**
 * How complete a freelancer's profile is (FL-103): a percentage and the steps still missing, so the apps can show a
 * progress bar and say what to do next. It only measures — the account's status moves on its own steps (the terms,
 * then FL-108 / FL-110), never because the bar is full. The portfolio (FL-105) is a step too.
 */
class FreelancerCompleteness
{
    public const BIO_MIN = 50;

    /** step => weight; the weights add up to 100 */
    private const WEIGHTS = [
        'name' => 10,
        'avatar' => 10,
        'headline' => 10,
        'bio' => 10,
        'email' => 10,
        'languages' => 5,
        'experience' => 5,
        'specialties' => 15,
        'skills' => 15,
        'work' => 5,
        'portfolio' => 5,
    ];

    /** Fewer skills than this don't make a profile a profile. */
    public const SKILLS_MIN = 3;

    /** @return array{percent: int, missing: list<string>, steps: array<string, bool>} */
    public function of(Freelancer $freelancer): array
    {
        $steps = [
            'name' => filled($freelancer->name),
            'avatar' => $freelancer->getFirstMediaUrl('avatar') !== '',
            'headline' => filled($freelancer->headline),
            'bio' => mb_strlen(trim((string) $freelancer->bio)) >= self::BIO_MIN,
            'email' => $freelancer->email !== null && $freelancer->email_verified_at !== null,
            'languages' => ! empty($freelancer->contact_languages),
            'experience' => $freelancer->years_of_experience !== null,
            'specialties' => $freelancer->specialties()->exists(),
            'skills' => $freelancer->skills()->count() >= self::SKILLS_MIN,
            'work' => $freelancer->experiences()->exists(),
            'portfolio' => $freelancer->portfolioItems()->exists(),
        ];

        return [
            'percent' => (int) array_sum(array_map(fn (string $step) => $steps[$step] ? self::WEIGHTS[$step] : 0, array_keys($steps))),
            'missing' => array_keys(array_filter($steps, fn (bool $done) => ! $done)),
            'steps' => $steps,
        ];
    }
}
