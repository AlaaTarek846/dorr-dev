<?php

namespace Modules\Sports\Console;

use Illuminate\Console\Command;
use Modules\Sports\Support\ApiSportsClient;
use Throwable;

/**
 * `php artisan sports:check` — is the API-Sports key working, what plan is it, how much of
 * today's quota is left, and does the plan return today's real fixtures (some free plans only
 * cover past seasons)? Uses 2 requests. Never prints the key.
 */
class CheckSportsProvider extends Command
{
    protected $signature = 'sports:check';

    protected $description = 'Check the API-Sports key, plan, quota and access to current fixtures';

    public function handle(ApiSportsClient $client): int
    {
        if (! $client->configured()) {
            $this->error('API_SPORTS_KEY is empty. Put it in .env, then run: php artisan config:clear');

            return self::FAILURE;
        }

        try {
            $status = $client->get('football', 'status');
            if ($this->failed('status', $status['errors'])) {
                return self::FAILURE;
            }
            $s = (array) $status['response'];
            $this->info('Key OK.');
            $this->line('Plan: '.data_get($s, 'subscription.plan', '?').' · active: '.(data_get($s, 'subscription.active') ? 'yes' : 'no').' · ends: '.data_get($s, 'subscription.end', '?'));
            $this->line('Requests today (provider): '.data_get($s, 'requests.current', '?').' / '.data_get($s, 'requests.limit_day', '?'));

            $today = now()->toDateString();
            $fixtures = $client->get('football', 'fixtures', ['date' => $today]);
            if ($this->failed('fixtures today', $fixtures['errors'])) {
                $this->warn('This plan does not return current fixtures — DORR Sports needs a plan that covers the current season.');

                return self::FAILURE;
            }
            $this->info("Fixtures on {$today}: {$fixtures['results']} — current season data is available.");
            $first = collect((array) $fixtures['response'])->take(3)->map(fn ($f) => data_get($f, 'league.name').': '.data_get($f, 'teams.home.name').' – '.data_get($f, 'teams.away.name'));
            $first->each(fn ($line) => $this->line('  · '.$line));
        } catch (Throwable $e) {
            $this->error('Could not reach API-Sports: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Left today (this server\'s count): '.$client->leftToday());

        return self::SUCCESS;
    }

    private function failed(string $what, mixed $errors): bool
    {
        if (empty($errors)) {
            return false;
        }
        $this->error("{$what}: ".json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return true;
    }
}
