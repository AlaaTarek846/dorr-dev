<?php

namespace Modules\Sports\Console;

use Illuminate\Console\Command;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Services\SportsEngine;

/** `sports:import football` — the sport's current competitions (one request). */
class ImportSportsCompetitions extends Command
{
    protected $signature = 'sports:import {sport=football} {--no-suggest : start every new competition off}';

    protected $description = 'Import a sport\'s current competitions from the provider';

    public function handle(SportsEngine $engine): int
    {
        $sport = SportsSport::query()->where('key', $this->argument('sport'))->first();
        if ($sport === null) {
            $this->error('Unknown sport. Run the SportsSeeder first.');

            return self::FAILURE;
        }
        $r = $engine->importCompetitions($sport, ! $this->option('no-suggest'));
        $this->info("created {$r['created']}, updated {$r['updated']}");

        return self::SUCCESS;
    }
}
