<?php

namespace App\Services\Security;

use App\Models\AccountSession;
use App\Models\Country;
use App\Models\SecurityEvent;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Intrusion alerts (spec item 27): the account owner is told at once — in-app, live and by push, in their own
 * language — when someone seems to be trying to get into the account:
 *  - several wrong sign-in codes / passwords in a short time,
 *  - many sign-in codes asked for the number in a short time,
 *  - a sign-in from a device the account has never used.
 * Each kind is sent at most once per window, so an attack does not become a flood of notifications.
 */
class SecurityAlerts
{
    public function __construct(private readonly NotificationCenter $center) {}

    /** After a wrong code or password: alert once enough of them piled up in the window. */
    public function failedAttempt(Model $owner): void
    {
        $count = $this->recent($owner, [SecurityEvent::OTP_FAILED, SecurityEvent::LOGIN_FAILED, SecurityEvent::OTP_LOCKED]);

        if ($count >= (int) config('auth_flow.security_failed_attempts', 3) && $this->once($owner, 'failed')) {
            $this->send($owner, 'failed_attempts', ['count' => $count]);
        }
    }

    /** After a sign-in code was asked for: alert when the number is being asked for again and again. */
    public function codeRequested(Model $owner): void
    {
        $count = $this->recent($owner, [SecurityEvent::OTP_REQUESTED]);

        if ($count >= (int) config('auth_flow.security_code_requests', 5) && $this->once($owner, 'codes')) {
            $this->send($owner, 'code_requests', ['count' => $count]);
        }
    }

    /** A sign-in from a device this account never used. */
    public function newDevice(Model $owner, AccountSession $session): void
    {
        $country = $session->country_code
            ? (Country::query()->where('code', $session->country_code)->with('translations')->first()?->translatedName() ?? $session->country_code)
            : '—';

        $this->send($owner, 'new_device', [
            'device' => $session->device_name ?: '—',
            'country' => $country,
        ]);
    }

    /** @param list<string> $types */
    private function recent(Model $owner, array $types): int
    {
        return SecurityEvent::query()->of($owner)->whereIn('type', $types)
            ->where('created_at', '>=', now()->subMinutes($this->window()))
            ->count();
    }

    private function once(Model $owner, string $kind): bool
    {
        return Cache::add("security-alert:{$kind}:{$owner->getMorphClass()}:{$owner->getKey()}", 1, now()->addMinutes($this->window()));
    }

    private function window(): int
    {
        return (int) config('auth_flow.security_window_minutes', 15);
    }

    /** @param array<string, scalar> $variables */
    private function send(Model $owner, string $kind, array $variables): void
    {
        $this->center->send(
            $owner,
            'security.alert',
            "security_alert_{$kind}_title",
            "security_alert_{$kind}_body",
            $variables,
            ['type' => 'security', 'alert' => $kind],
        );
    }
}
