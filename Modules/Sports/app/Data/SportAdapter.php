<?php

namespace Modules\Sports\Data;

/**
 * One sport's provider API, turned into the shape every sport shares (spec 183):
 *
 * match = [provider_id, competition{provider_id, name, country, logo, flag, season, round},
 *          home{provider_id, name, code, logo, national}, away{…}, starts_at (ISO, UTC),
 *          status (scheduled · live · break · finished · postponed · cancelled · suspended),
 *          status_code, status_label, elapsed, elapsed_extra, home_score, away_score,
 *          scores{periods[{label, home, away}], …}, winner (home · away · draw), venue, city, referee,
 *          events (null = not in this answer) [{key, minute, extra, side, type, player, assist, detail}],
 *          statistics (null | [{type, home, away}]), lineups (null | {home{…}, away{…}})]
 */
interface SportAdapter
{
    /** @return list<array<string, mixed>> current competitions with their season */
    public function competitions(): array;

    /** @return list<array<string, mixed>> matches on that UTC day */
    public function schedule(string $date): array;

    /**
     * What's live now in these competitions (one request when the API allows it).
     *
     * @param  list<int>  $competitionIds  provider ids
     * @param  list<string>  $dates  the UTC days of the matches being followed
     * @return list<array<string, mixed>>
     */
    public function live(array $competitionIds, array $dates): array;

    /** Whether details() gives events, statistics and line-ups. */
    public function hasDetails(): bool;

    /**
     * @param  list<int>  $matchIds  provider ids (up to 20)
     * @return list<array<string, mixed>>
     */
    public function details(array $matchIds): array;

    /** @return list<array<string, mixed>> [{group, rank, team{…}, points, played, win, draw, lose, goals_for, goals_against, goal_diff, form, description, updated_at}] */
    public function standings(int $competitionId, string $season): array;

    /** @return list<array<string, mixed>> [{rank, player, photo, team, team_logo, goals, assists}] */
    public function topScorers(int $competitionId, string $season): array;
}
