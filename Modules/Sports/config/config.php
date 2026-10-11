<?php

/*
 * DORR Sports (spec 183–200, docs/sports-plan.md). Provider details only — what's enabled, the
 * tiers and the budget are the admin's (sports_settings / sports_competitions).
 */
return [
    // API-Sports: one API per sport, one key and one daily budget for all of them.
    'sports' => [
        'football' => ['url' => env('API_SPORTS_FOOTBALL_URL', 'https://v3.football.api-sports.io'), 'emoji' => '⚽', 'adapter' => \Modules\Sports\Data\FootballAdapter::class, 'window_minutes' => 135],
        'basketball' => ['url' => 'https://v1.basketball.api-sports.io', 'emoji' => '🏀', 'adapter' => \Modules\Sports\Data\GamesAdapter::class, 'window_minutes' => 150],
        'volleyball' => ['url' => 'https://v1.volleyball.api-sports.io', 'emoji' => '🏐', 'adapter' => \Modules\Sports\Data\GamesAdapter::class, 'window_minutes' => 150],
        'handball' => ['url' => 'https://v1.handball.api-sports.io', 'emoji' => '🤾', 'adapter' => \Modules\Sports\Data\GamesAdapter::class, 'window_minutes' => 100],
        'hockey' => ['url' => 'https://v1.hockey.api-sports.io', 'emoji' => '🏒', 'adapter' => \Modules\Sports\Data\GamesAdapter::class, 'window_minutes' => 170],
        'rugby' => ['url' => 'https://v1.rugby.api-sports.io', 'emoji' => '🏉', 'adapter' => \Modules\Sports\Data\GamesAdapter::class, 'window_minutes' => 110],
        'baseball' => ['url' => 'https://v1.baseball.api-sports.io', 'emoji' => '⚾', 'adapter' => \Modules\Sports\Data\GamesAdapter::class, 'window_minutes' => 200],
        'formula1' => ['url' => 'https://v1.formula-1.api-sports.io', 'emoji' => '🏎️', 'adapter' => \Modules\Sports\Data\RacesAdapter::class, 'window_minutes' => 150],
        'mma' => ['url' => 'https://v1.mma.api-sports.io', 'emoji' => '🥊', 'adapter' => \Modules\Sports\Data\FightsAdapter::class, 'window_minutes' => 30],
    ],

    // Default live update every N seconds by tier (the admin can change them).
    'tier_seconds' => ['big' => 60, 'normal' => 180, 'minor' => 480],

    // Never poll faster than the provider updates its live data (~15 s).
    'min_seconds' => 30,

    // Standings and top-scorer requests per hourly run (tables never eat the live budget at once).
    'standings_per_run' => 6,

    // Whole-season schedules per hourly run (one request each).
    'seasons_per_run' => 4,

    // How long each provider answer stays good, in seconds (the provider's "Recommended Calls").
    'ttl' => [
        'team_profile' => 7 * 86400, 'coach' => 7 * 86400, 'squad' => 7 * 86400, 'transfers' => 7 * 86400, 'trophies' => 7 * 86400,
        'sidelined' => 7 * 86400, 'player_profile' => 7 * 86400, 'player_career' => 7 * 86400,
        'player_stats' => 86400, 'team_stats' => 86400, 'team_stats_idle' => 3 * 86400, 'leaders' => 86400, 'rounds' => 86400,
        'h2h' => 86400, 'prediction' => 6 * 3600, 'injuries' => 4 * 3600, 'odds' => 3 * 3600, 'search' => 86400, 'history' => 30 * 86400,
    ],

    // When the budget is short, intervals stretch up to this.
    'max_seconds' => 1800,

    // A first import suggests these competitions (by provider id) as "big"; the admin decides.
    'suggested' => [
        'football' => [307, 233, 301, 305, 39, 140, 135, 78, 61, 2, 3, 1, 4, 6, 7, 17, 12, 15],
    ],
];
