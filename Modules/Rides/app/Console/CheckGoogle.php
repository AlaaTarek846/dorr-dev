<?php

namespace Modules\Rides\Console;

use Illuminate\Console\Command;
use Modules\Rides\Services\Geo\GoogleKeyCheck;

/** "Does my Google key work?" — one real call to each Google API the rides use, and what Google said. */
class CheckGoogle extends Command
{
    protected $signature = 'rides:check-google';

    protected $description = 'Test the Google Maps server key against the Routes and Geocoding APIs';

    public function handle(GoogleKeyCheck $check): int
    {
        $result = $check->run();

        $this->line($result['key_set']
            ? 'Key: '.$result['hint'].' (from '.($result['source'] === 'admin' ? 'the admin Settings' : '.env GOOGLE_MAPS_SERVER_KEY').')'
            : 'Key: none');

        foreach ($result['checks'] as $row) {
            $line = sprintf('%-10s %s%s', $row['api'], $row['ok'] ? 'OK    ' : 'FAILED', $row['http'] ? "  HTTP {$row['http']}" : '').($row['status'] ? "  {$row['status']}" : '');
            $row['ok'] ? $this->info($line) : $this->error($line);
            $this->line('           '.$row['hint']);
            if (! $row['ok'] && $row['message']) {
                $this->line('           Google said: '.$row['message']);
            }
        }

        foreach ($result['paused'] as $api => $reason) {
            if ($reason !== null) {
                $this->warn("{$api}: Google is being left alone ({$reason}); the straight-line estimate answers meanwhile.");
            }
        }

        $this->line('Map provider in use (admin → Rides → Settings): '.$result['provider'].'.');

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
