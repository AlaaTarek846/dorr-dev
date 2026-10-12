<?php

namespace App\Services\Security;

use Illuminate\Http\Request;

/**
 * What a request says about the device it comes from. The app sends `X-Device-Id` (a random id it keeps for the
 * install), `X-Device-Name`, `X-Platform` and `X-App-Version`; a browser sends none of them and is taken as "web".
 * Everything is cleaned and cut to size: it is shown back to the account owner and the dashboard.
 */
final class DeviceContext
{
    public function __construct(
        public readonly ?string $deviceId,
        public readonly ?string $deviceName,
        public readonly ?string $platform,
        public readonly ?string $appVersion,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
    ) {}

    public static function fromRequest(?Request $request = null): self
    {
        $request ??= request();

        $deviceId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $request->header('X-Device-Id'));
        $platform = strtolower((string) $request->header('X-Platform'));
        $userAgent = mb_substr(trim((string) $request->userAgent()), 0, 255);

        if (! in_array($platform, ['android', 'ios', 'web'], true)) {
            $platform = match (true) {
                str_contains(strtolower($userAgent), 'okhttp') => 'android',
                $userAgent !== '' && preg_match('/mozilla|chrome|safari|firefox|edg/i', $userAgent) === 1 => 'web',
                default => null,
            };
        }

        return new self(
            deviceId: $deviceId !== '' ? mb_substr($deviceId, 0, 64) : null,
            deviceName: self::clean($request->header('X-Device-Name'), 120) ?? ($platform === 'web' ? self::browserName($userAgent) : null),
            platform: $platform,
            appVersion: self::clean($request->header('X-App-Version'), 20),
            ip: $request->ip(),
            userAgent: $userAgent !== '' ? $userAgent : null,
        );
    }

    private static function clean(mixed $value, int $max): ?string
    {
        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** "Chrome on Windows" from a browser's user agent, for a readable device name. */
    private static function browserName(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "{$browser} · {$os}" : $browser;
    }
}
