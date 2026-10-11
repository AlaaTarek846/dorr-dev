<?php

namespace Modules\Sports\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Services\SportsEngine;
use Modules\Sports\Support\ApiSportsClient;
use Throwable;

/**
 * After `migrate:fresh --seed`: the football competitions from the provider (1 request) and
 * today's and tomorrow's matches (2 requests), so Sports isn't empty. Suggested competitions
 * start "big" (config sports.suggested). Locally, the 40 leagues that play most today and
 * tomorrow are turned on too ("normal"), so there's always something to watch while developing.
 *
 * Skipped in tests and without API_SPORTS_KEY; a provider problem never stops the seeding.
 */
class SportsProviderSeeder extends Seeder
{
    /** Local only: how many of the busiest leagues of the next two days to turn on. */
    private const LOCAL_LEAGUES = 40;

    public function run(SportsEngine $engine, ApiSportsClient $client): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        if (! $client->configured()) {
            $this->command?->warn('Sports: API_SPORTS_KEY is not set — no competitions imported (run `php artisan sports:import football` later).');

            return;
        }
        $sport = SportsSport::query()->where('key', 'football')->first();
        if ($sport === null) {
            return;
        }

        try {
            $import = $engine->importCompetitions($sport);
            $this->command?->info('Sports: competitions '.json_encode($import));

            $dates = [now()->utc()->toDateString(), now()->utc()->addDay()->toDateString()];
            $rows = [];
            foreach ($dates as $date) {
                $rows[$date] = $engine->adapter($sport)->schedule($date);
            }

            if (app()->environment('local')) {
                $this->turnOnBusiestLeagues($sport, $rows);
            }

            // Apply what was read (no more requests): the matches of every active competition.
            $active = SportsCompetition::query()->where('sport_id', $sport->id)->active()->get()->keyBy('provider_id');
            $saved = 0;
            foreach ($rows as $list) {
                foreach ($list as $n) {
                    if (($c = $active->get($n['competition']['provider_id'])) !== null) {
                        $engine->apply($sport, $c, $n);
                        $saved++;
                    }
                }
            }
            $this->command?->info("Sports: {$active->count()} active competitions, {$saved} matches today and tomorrow ({$client->usedToday()} requests used today).");
        } catch (Throwable $e) {
            $this->command?->warn('Sports: the provider could not be read now ('.$e->getMessage().'). Run `php artisan sports:import football` and `php artisan sports:schedule` later.');
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows  matches by date
     */
    private function turnOnBusiestLeagues(SportsSport $sport, array $rows): void
    {
        $counts = [];
        foreach ($rows as $list) {
            foreach ($list as $n) {
                $id = $n['competition']['provider_id'];
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }
        arsort($counts);
        $on = 0;
        foreach (array_slice(array_keys($counts), 0, self::LOCAL_LEAGUES) as $providerId) {
            $on += SportsCompetition::query()->where('sport_id', $sport->id)->where('provider_id', $providerId)->where('tier', 'off')
                ->update(['tier' => 'normal', 'priority' => 10]);
        }
        $this->command?->info("Sports (local): {$on} more leagues playing today and tomorrow turned on.");
    }
}
