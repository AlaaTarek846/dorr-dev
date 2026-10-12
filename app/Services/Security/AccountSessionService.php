<?php

namespace App\Services\Security;

use App\Models\AccountSession;
use App\Models\NotificationDevice;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The devices an account is signed in on (spec item 29). Every sign-in gets its own token and session, so an account
 * can be open on several devices (up to `auth_flow.max_sessions`; past it the oldest one is signed out). The owner sees
 * them all and can sign any of them out at once: its token is deleted (its next request is a 401) and its push id is
 * dropped, so it stops getting the account's notifications too.
 */
class AccountSessionService
{
    public function __construct(
        private readonly SecurityLog $log,
        private readonly SecurityAlerts $alerts,
    ) {}

    /**
     * Signs the owner in on the device the request comes from and returns the new plain-text token. The same device
     * signing in again replaces its previous session; a device the account never used raises a "new device" alert.
     *
     * @return array{0: string, 1: AccountSession}
     */
    public function signIn(Model $owner, string $tokenName, ?DeviceContext $device = null): array
    {
        $device ??= DeviceContext::fromRequest();

        // Read before this sign-in is written: has the account been used before, and on this device?
        $usedBefore = AccountSession::query()->of($owner)->exists();
        $knownDevice = $device->deviceId !== null
            && AccountSession::query()->of($owner)->where('device_id', $device->deviceId)->exists();

        [$token, $session] = DB::transaction(function () use ($owner, $tokenName, $device) {
            if ($device->deviceId !== null) {
                AccountSession::query()->of($owner)->active()->where('device_id', $device->deviceId)->get()
                    ->each(fn (AccountSession $old) => $this->end($old, AccountSession::REASON_REPLACED));
            }

            $token = $owner->createToken($tokenName);

            $session = AccountSession::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'token_id' => $token->accessToken->getKey(),
                'device_id' => $device->deviceId,
                'device_name' => $device->deviceName,
                'platform' => $device->platform,
                'app_version' => $device->appVersion,
                'ip' => $device->ip,
                'country_code' => SecurityLog::countryOf($device->ip),
                'user_agent' => $device->userAgent,
            ]);

            return [$token->plainTextToken, $session];
        });

        // A device the account never used — only when the app says which device it is (an unknown one is not
        // flagged: every browser sign-in would look "new").
        $newDevice = $usedBefore && $device->deviceId !== null && ! $knownDevice;
        $this->log->record($owner, $newDevice ? SecurityEvent::LOGIN_NEW_DEVICE : SecurityEvent::LOGIN, [], $session, $device);

        $this->enforceLimit($owner, $session);

        if ($newDevice) {
            $this->alerts->newDevice($owner, $session);
        }

        return [$token, $session];
    }

    /** Signs out the device the owner's current request comes from (the app's own "log out"). */
    public function signOutCurrent(Model $owner): void
    {
        $session = $this->current($owner);

        if ($session !== null) {
            $this->log->record($owner, SecurityEvent::LOGOUT, [], $session);
            $this->end($session, AccountSession::REASON_LOGOUT);

            return;
        }

        $owner->currentAccessToken()?->delete();
    }

    /** The owner signs one of their devices out. */
    public function signOut(Model $owner, AccountSession $session, string $reason = AccountSession::REASON_USER): void
    {
        $type = $reason === AccountSession::REASON_ADMIN ? SecurityEvent::ADMIN_SIGNED_OUT : SecurityEvent::DEVICE_SIGNED_OUT;

        $this->log->record($owner, $type, ['device' => $session->device_name, 'platform' => $session->platform]);
        $this->end($session, $reason);
    }

    /** Every device but the one the request comes from. @return int how many were signed out */
    public function signOutOthers(Model $owner, string $reason = AccountSession::REASON_OTHERS): int
    {
        $current = $this->current($owner);
        $others = $this->active($owner)->reject(fn (AccountSession $s) => $s->id === $current?->id);

        $others->each(fn (AccountSession $s) => $this->end($s, $reason));

        if ($others->isNotEmpty()) {
            $this->log->record(
                $owner,
                $reason === AccountSession::REASON_ADMIN ? SecurityEvent::ADMIN_SIGNED_OUT : SecurityEvent::OTHER_DEVICES_SIGNED_OUT,
                ['count' => $others->count()],
                $current,
            );
        }

        return $others->count();
    }

    /**
     * The devices signed in now, newest activity first. A token made before this feature (or by another way in) gets
     * its session row here, so every device shows up.
     *
     * @return Collection<int, AccountSession>
     */
    public function active(Model $owner): Collection
    {
        $this->adoptLegacyTokens($owner);

        [$live, $gone] = AccountSession::query()->of($owner)->active()->with('token')->get()
            ->partition(fn (AccountSession $s) => $s->token_id === null || $s->token !== null);

        // Its token was deleted some other way (account deleted, blocked…): that device is signed out already.
        $gone->each(fn (AccountSession $s) => $this->end($s, AccountSession::REASON_LOGOUT));

        return $live
            ->sortByDesc(fn (AccountSession $s) => ($s->token?->last_used_at ?? $s->created_at)?->getTimestamp() ?? 0)
            ->values();
    }

    /** The session of the owner's current request (made on the spot for a token from before this feature). */
    public function current(Model $owner): ?AccountSession
    {
        $token = method_exists($owner, 'currentAccessToken') ? $owner->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        return AccountSession::query()->where('token_id', $token->getKey())->first()
            ?? $this->adopt($owner, $token, DeviceContext::fromRequest());
    }

    /** Ends a session: its token goes (signed out at once) and its device stops getting this account's pushes. */
    public function end(AccountSession $session, string $reason): void
    {
        if ($session->revoked_at !== null) {
            return;
        }

        if ($session->token_id !== null) {
            PersonalAccessToken::query()->whereKey($session->token_id)->delete();
        }

        if ($session->device_id !== null) {
            NotificationDevice::query()
                ->where('owner_type', $session->owner_type)
                ->where('owner_id', $session->owner_id)
                ->where('device_id', $session->device_id)
                ->delete();
        }

        $session->forceFill(['revoked_at' => now(), 'revoked_reason' => $reason])->save();
    }

    /** Past the allowed number of devices, the ones used longest ago are signed out. */
    private function enforceLimit(Model $owner, AccountSession $keep): void
    {
        $max = max(1, (int) config('auth_flow.max_sessions', 5));
        $active = $this->active($owner)->reject(fn (AccountSession $s) => $s->id === $keep->id);

        $active->slice($max - 1)->each(function (AccountSession $old) use ($owner, $keep) {
            $this->log->record($owner, SecurityEvent::SESSION_LIMIT, ['device' => $old->device_name, 'platform' => $old->platform], $keep);
            $this->end($old, AccountSession::REASON_LIMIT);
        });
    }

    private function adoptLegacyTokens(Model $owner): void
    {
        if (! method_exists($owner, 'tokens')) {
            return;
        }

        $known = AccountSession::query()->of($owner)->whereNotNull('token_id')->pluck('token_id');

        $owner->tokens()->whereNotIn('id', $known)->get()
            ->each(fn (PersonalAccessToken $token) => $this->adopt($owner, $token, null));
    }

    private function adopt(Model $owner, PersonalAccessToken $token, ?DeviceContext $device): AccountSession
    {
        $session = new AccountSession;
        $session->forceFill([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'token_id' => $token->getKey(),
            'device_id' => $device?->deviceId,
            'device_name' => $device?->deviceName,
            'platform' => $device?->platform ?? ($token->name === 'mobile-app' ? 'android' : ($token->name === 'user-api' ? 'web' : null)),
            'app_version' => $device?->appVersion,
            'ip' => $device?->ip,
            'user_agent' => $device?->userAgent,
            // Signed in when the token was made, not now.
            'created_at' => $token->created_at ?? now(),
        ])->save();

        return $session;
    }
}
