<?php

namespace App\Services\Notifications;

use App\Models\NotificationDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * VoIP pushes straight to Apple (APNs, HTTP/2, token auth) — only for a ringing chat call, so an
 * iPhone rings full screen through CallKit even locked or closed (Android's full-screen notification).
 * OneSignal doesn't carry VoIP pushes, so this is the one push that skips it. No package: the JWT is
 * signed with openssl (ES256) and kept for 50 minutes (Apple wants it renewed under an hour).
 * Without `services.apns.*` it does nothing and the caller falls back to OneSignal.
 */
class ApnsVoipPusher
{
    public function configured(): bool
    {
        return filled(config('services.apns.key_id')) && filled(config('services.apns.team_id')) && filled(config('services.apns.key'));
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, mixed>  $payload  what the app reads to show the call (no `aps` alert — CallKit draws it)
     */
    public function send(array $tokens, array $payload, int $ttlSeconds): void
    {
        if (! $this->configured() || $tokens === []) {
            return;
        }

        $jwt = $this->jwt();
        if ($jwt === null) {
            return;
        }

        $host = config('services.apns.production') ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
        $body = ['aps' => (object) []] + $payload;

        foreach (array_unique($tokens) as $token) {
            try {
                $response = Http::withOptions(['version' => 2.0])
                    ->timeout((int) config('services.apns.timeout', 10))
                    ->withHeaders([
                        'authorization' => 'bearer '.$jwt,
                        'apns-topic' => config('services.apns.bundle_id').'.voip',
                        'apns-push-type' => 'voip',
                        'apns-priority' => '10',
                        'apns-expiration' => (string) (time() + max($ttlSeconds, 0)),
                    ])
                    ->post("{$host}/3/device/{$token}", $body);

                // A token the phone no longer has (app removed, another build): forget it.
                $reason = (string) $response->json('reason');
                if ($response->status() === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
                    NotificationDevice::query()->where('voip_token', $token)->update(['voip_token' => null]);
                } elseif (! $response->successful()) {
                    Log::warning('[ApnsVoipPusher] '.$response->status().' '.$reason);
                }
            } catch (Throwable $e) {
                Log::error('[ApnsVoipPusher] '.$e->getMessage());
            }
        }
    }

    private function jwt(): ?string
    {
        return Cache::remember('apns.voip.jwt.'.config('services.apns.key_id'), now()->addMinutes(50), function () {
            $key = (string) config('services.apns.key');
            // A path (absolute, or from the project root like storage/app/apns/AuthKey.p8), or the key itself.
            $file = is_file($key) ? $key : (is_file(base_path($key)) ? base_path($key) : null);
            $pem = $file ? (string) file_get_contents($file) : str_replace('\n', "\n", $key);
            $private = openssl_pkey_get_private($pem);
            if ($private === false) {
                Log::error('[ApnsVoipPusher] the APNs key is not a valid .p8');

                return null;
            }

            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $head = $b64(json_encode(['alg' => 'ES256', 'kid' => config('services.apns.key_id')]));
            $claims = $b64(json_encode(['iss' => config('services.apns.team_id'), 'iat' => time()]));
            if (! openssl_sign("{$head}.{$claims}", $der, $private, OPENSSL_ALGO_SHA256)) {
                return null;
            }

            return "{$head}.{$claims}.".$b64(self::derToRaw($der));
        });
    }

    /** openssl signs ECDSA in DER; a JWT wants the raw r‖s, 32 bytes each. */
    private static function derToRaw(string $der): string
    {
        $offset = 3 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $rLength = ord($der[$offset]);
        $r = substr($der, $offset + 1, $rLength);
        $offset += 1 + $rLength + 1;
        $sLength = ord($der[$offset]);
        $s = substr($der, $offset + 1, $sLength);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT).str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}
