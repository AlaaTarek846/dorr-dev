<?php

namespace App\Services\Security;

use App\Models\AccountSession;
use App\Models\SecurityEvent;

/**
 * How a device and a security-log line are shown — to the account owner (app) and to the dashboard. The owner sees a
 * partly hidden IP; the dashboard sees all of it.
 */
class SecurityPresenter
{
    /** @return array<string, mixed> */
    public static function session(AccountSession $session, ?int $currentId = null, bool $fullIp = false): array
    {
        return [
            'id' => $session->id,
            'device_name' => $session->device_name ?: __('security.unknown_device'),
            'platform' => $session->platform,
            'platform_label' => __('security.platforms.'.($session->platform ?: 'unknown')),
            'app_version' => $session->app_version,
            'ip' => $fullIp ? $session->ip : SecurityLog::maskIp($session->ip),
            'country_code' => $session->country_code,
            'current' => $currentId !== null && $session->id === $currentId,
            'signed_in_at' => $session->created_at?->toIso8601String(),
            'last_active_at' => ($session->token?->last_used_at ?? $session->created_at)?->toIso8601String(),
            'revoked_at' => $session->revoked_at?->toIso8601String(),
            'revoked_reason' => $session->revoked_reason,
        ];
    }

    /** @return array<string, mixed> */
    public static function event(SecurityEvent $event, bool $fullIp = false): array
    {
        $meta = $event->meta ?? [];

        return [
            'id' => $event->id,
            'type' => $event->type,
            'label' => __('security.events.'.$event->type),
            'description' => self::describe($event->type, $meta),
            'warning' => $event->isWarning(),
            'device_name' => $event->device_name,
            'platform' => $event->platform,
            'platform_label' => __('security.platforms.'.($event->platform ?: 'unknown')),
            'ip' => $fullIp ? $event->ip : SecurityLog::maskIp($event->ip),
            'country_code' => $event->country_code,
            'meta' => $meta,
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    /** One readable line of detail, when the event has something to add. */
    private static function describe(string $type, array $meta): ?string
    {
        // Support signing every device out logs a count rather than one device.
        $key = 'security.details.'.$type.($type === SecurityEvent::ADMIN_SIGNED_OUT && isset($meta['count']) ? '_all' : '');
        $values = array_merge($meta, [
            'device' => ($meta['device'] ?? null) ?: __('security.unknown_device'),
            'flow' => isset($meta['flow']) ? __('security.flows.'.$meta['flow']) : '',
        ]);
        $text = __($key, array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $values));

        return $text === $key ? null : $text;
    }
}
