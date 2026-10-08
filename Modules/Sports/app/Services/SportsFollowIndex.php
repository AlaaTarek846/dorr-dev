<?php

namespace Modules\Sports\Services;

use Illuminate\Support\Facades\DB;

/** What people follow, for the engine: followed matches update faster, unwatched ones slower. */
class SportsFollowIndex
{
    /** @return array<int, true> team ids */
    public static function teams(int $sportId): array
    {
        return array_fill_keys(DB::table('sports_follows')->where('sport_id', $sportId)->where('kind', 'team')->distinct()->pluck('target_id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /**
     * Competitions someone follows, or where a followed team plays this month.
     *
     * @return array<int, true>
     */
    public static function competitions(int $sportId): array
    {
        $direct = DB::table('sports_follows')->where('sport_id', $sportId)->where('kind', 'competition')->distinct()->pluck('target_id')->all();
        $teams = array_keys(self::teams($sportId));
        $viaTeams = $teams === [] ? [] : DB::table('sports_matches')->where('sport_id', $sportId)
            ->whereBetween('starts_at', [now()->subDays(30), now()->addDays(30)])
            ->where(fn ($q) => $q->whereIn('home_team_id', $teams)->orWhereIn('away_team_id', $teams))
            ->distinct()->pluck('competition_id')->all();

        return array_fill_keys(array_map('intval', array_merge($direct, $viaTeams)), true);
    }
}
