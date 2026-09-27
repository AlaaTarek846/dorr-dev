<?php

namespace Modules\Chat\Support;

use Modules\Chat\Exceptions\ChatException;

/**
 * A LiveKit access token: a JWT (HS256) signed with the API secret, granting one identity the
 * right to join one room (https://docs.livekit.io/home/get-started/authentication/).
 *
 * Signed here in a few lines instead of pulling in an SDK: the format is small and stable, and
 * it keeps the secret on the server — the app only ever receives the finished token.
 */
class LiveKitToken
{
    /**
     * @param  array<string, mixed>  $metadata  shown to the other people in the room (name, avatar)
     */
    public static function issue(string $room, string $identity, string $name, array $metadata = []): string
    {
        $key = (string) config('chat.livekit.api_key');
        $secret = (string) config('chat.livekit.api_secret');

        if ($key === '' || $secret === '') {
            throw ChatException::callsNotConfigured();
        }

        $now = time();

        $claims = [
            'iss' => $key,
            'sub' => $identity,
            'name' => $name,
            'nbf' => $now - 10,
            'exp' => $now + (int) config('chat.livekit.token_ttl_seconds', 21600),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ];

        $segments = [
            self::base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])),
            self::base64Url(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];

        $segments[] = self::base64Url(hash_hmac('sha256', implode('.', $segments), $secret, true));

        return implode('.', $segments);
    }

    public static function url(): ?string
    {
        return config('chat.livekit.url') ?: null;
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
