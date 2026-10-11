<?php

namespace Modules\Sports\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sports\Data\FootballAdapter;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsTeam;
use Modules\Sports\Services\SportsEngine;

/**
 * Run once after upgrading the API-Sports plan (or changing the key): everything the old plan
 * refused (the current season, `ids`, `last`, dates…) is asked again from the next run instead
 * of waiting out the week, and tables, leaders and seasons become due at once. No request is
 * sent here.
 */
class SportsPlanChanged extends Command
{
    protected $signature = 'sports:plan-changed';

    protected $description = 'Forget what the old API-Sports plan refused, so everything is read again now';

    public function handle(): int
    {
        // The stored answers the plan refused (squads, statistics, leaders, predictions…).
        $refused = DB::table('sports_cache')->whereNotNull('refused_until')->update(['refused_until' => null, 'expires_at' => now(), 'updated_at' => now()]);

        // Refusals kept in the cache (tables, top scorers, seasons, a team's season): a new generation.
        Cache::forever(SportsEngine::PLAN_GENERATION, (int) Cache::get(SportsEngine::PLAN_GENERATION, 0) + 1);
        Cache::forget(FootballAdapter::NO_IDS);
        // The new daily limit is read again from the provider's next answer.
        Cache::forget('sports.provider_limit');

        // Tables, top scorers and seasons are due now (failed reads had marked them as done).
        $competitions = SportsCompetition::query()->active()->update(['standings_synced_at' => null, 'scorers_synced_at' => null, 'season_synced_at' => null]);
        $teams = SportsTeam::query()->whereNotNull('season_synced_at')->update(['season_synced_at' => null]);

        $this->info("Forgotten: {$refused} refused answers. Due again: {$competitions} active competitions, {$teams} teams' seasons.");
        $this->line('Check the new plan with `php artisan sports:check` (2 requests). Tables and seasons fill in over the next hours (sports:standings and sports:seasons, hourly).');

        return self::SUCCESS;
    }
}
