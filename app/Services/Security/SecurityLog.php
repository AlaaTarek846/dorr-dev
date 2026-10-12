<?php

namespace App\Services\Security;

use App\Models\AccountSession;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Writes the account's security log (spec item 28). Best effort: a log line that cannot be written is reported and
 * never fails the sign-in, the PIN check or whatever caused it.
 */
class SecurityLog
{
    /**
     * @param  array<string, mixed>  $meta  extra details shown in the log (never a code, a PIN or a token)
     */
    public function record(Model $owner, string $type, array $meta = [], ?AccountSession $session = null, ?DeviceContext $device = null): ?SecurityEvent
    {
        try {
            $device ??= DeviceContext::fromRequest();
            $session ??= $this->currentSession($owner);

            return SecurityEvent::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'type' => $type,
                'account_session_id' => $session?->id,
                'ip' => $device->ip ?? $session?->ip,
                'country_code' => $session?->country_code ?? self::countryOf($device->ip),
                'device_id' => $device->deviceId ?? $session?->device_id,
                'device_name' => $device->deviceName ?? $session?->device_name,
                'platform' => $device->platform ?? $session?->platform,
                'meta' => $meta === [] ? null : $meta,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** The device session the owner's current request is signed in with, if any. */
    public function currentSession(Model $owner): ?AccountSession
    {
        if (! method_exists($owner, 'currentAccessToken')) {
            return null;
        }

        $token = $owner->currentAccessToken();

        return $token instanceof PersonalAccessToken
            ? AccountSession::query()->where('token_id', $token->getKey())->first()
            : null;
    }

    /**
     * The country of a public IP (the lookup is cached per IP). A private or local address has none — it is not
     * guessed, so the log never shows a made-up country.
     */
    public static function countryOf(?string $ip): ?string
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        try {
            $code = strtoupper((string) getCountryCodeByIp());

            return strlen($code) === 2 ? $code : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** "192.168.1.•••" — enough to recognise a network, not to pinpoint someone. */
    public static function maskIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '•••';

            return implode('.', $parts);
        }

        $parts = explode(':', $ip);

        return implode(':', array_slice($parts, 0, 3)).':•••';
    }
}
