<?php

namespace Modules\Chat\Console;

use App\Models\NotificationDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Http;
use Modules\Chat\Support\ParticipantType;
use Throwable;

/**
 * "Notifications and live chat don't work" — what's wrong on this server, without printing a
 * secret: the real-time settings the phones get, a test event to Pusher, OneSignal's keys, and
 * (with --user) the phones registered for one account plus a test push to them.
 */
class RealtimeCheck extends Command
{
    protected $signature = 'chat:realtime-check {--user= : a user id: list their phones and send them a test push}';

    protected $description = 'Check the chat\'s real-time (Pusher) and push (OneSignal) setup.';

    public function handle(): int
    {
        $ok = true;

        // ------------------------------------------------------------ real-time
        $driver = (string) config('broadcasting.default');
        $connection = (array) config("broadcasting.connections.{$driver}", []);
        $host = (string) ($connection['options']['host'] ?? '');
        $this->line("Real-time driver: {$driver}");
        if (! in_array($driver, ['pusher', 'reverb'], true)) {
            $this->error('  BROADCAST_CONNECTION must be pusher (it is "'.$driver.'"): nothing reaches the phones.');
            $ok = false;
        } else {
            $this->line('  key: '.(empty($connection['key']) ? 'MISSING' : 'set').' · secret: '.(empty($connection['secret']) ? 'MISSING' : 'set').' · app id: '.(empty($connection['app_id']) ? 'MISSING' : 'set'));
            $this->line("  host: {$host} · cluster: ".($connection['options']['cluster'] ?? '-'));
            if (preg_match('/^(127\.|0\.0\.0\.0|localhost)/', $host)) {
                $this->error('  PUSHER_HOST points at this machine: phones can\'t reach it. For Pusher\'s cloud leave PUSHER_HOST empty (and PORT 443, SCHEME https).');
                $ok = false;
            }
            try {
                Broadcast::connection($driver)->broadcast(['chat-realtime-check'], 'check', ['at' => now()->toIso8601String()]);
                $this->info('  test event sent to Pusher: OK');
            } catch (Throwable $e) {
                $this->error('  test event failed: '.$e->getMessage());
                $ok = false;
            }
        }

        // ------------------------------------------------------------ push
        $appId = (string) config('services.onesignal.app_id');
        $key = (string) config('services.onesignal.rest_api_key');
        $this->line('OneSignal: app id '.($appId === '' ? 'MISSING' : 'set').' · REST key '.($key === '' ? 'MISSING' : 'set'));
        if ($appId === '' || $key === '') {
            $ok = false;
        } else {
            // A made-up phone: good keys answer 200 "not subscribed", bad ones 401/403.
            $response = rescue(fn () => Http::withHeaders(['Authorization' => 'Basic '.$key, 'Accept' => 'application/json'])->timeout(15)
                ->post('https://api.onesignal.com/notifications', ['app_id' => $appId, 'include_player_ids' => ['3f2b8c1e-7a4d-4e2b-9c1f-5d6e7f8a9b0c'], 'contents' => ['en' => 'check']]), null, false);
            if ($response?->successful()) {
                $this->info('  keys accepted by OneSignal: OK');
            } else {
                $this->error('  OneSignal refused the keys: '.($response?->status() ?? 'no answer').' '.mb_substr((string) $response?->body(), 0, 200));
                $ok = false;
            }
        }
        $this->line('Registered phones: '.NotificationDevice::query()->count().' (last: '.(NotificationDevice::query()->max('updated_at') ?? 'never').')');

        // ------------------------------------------------------------ one account
        if ($userId = $this->option('user')) {
            $class = ParticipantType::modelClassFor('user');
            $ids = NotificationDevice::query()->whereIn('owner_type', array_unique([$class, (new $class)->getMorphClass()]))->where('owner_id', $userId)->pluck('player_id')->all();
            $this->line("User {$userId}: ".count($ids).' phone(s)');
            if ($ids === []) {
                $this->warn('  No phone registered: open the app signed in (and allow notifications), then run this again.');
                $ok = false;
            } elseif (sendPushNotification(message: ['en' => 'DORR test notification', 'ar' => 'إشعار تجريبي من DORR'], playerIds: $ids, title: ['en' => 'DORR', 'ar' => 'DORR'])) {
                $this->info('  test push sent: check the phone.');
            } else {
                $this->error('  test push failed: see storage/logs/laravel.log ([sendPushNotification]).');
                $ok = false;
            }
        }

        $ok ? $this->info('All good.') : $this->error('Fix the lines above.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
