<?php

namespace Modules\Chat\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Modules\Chat\Enums\VerificationBadgeStatus;
use Modules\Chat\Models\Verification\ChatVerificationBadge;
use Modules\User\Models\User;
use Throwable;

/**
 * The one answer to "is this account verified?" (AV-026): every resource that shows a person's name asks
 * here, never the badge table itself. The verified accounts are kept in the cache as one small map
 * (user id → end time or null) and forgotten whenever a badge is saved, so a list of 50 names costs no
 * query at all and a suspended badge disappears from every screen at once.
 *
 * Only Dorr accounts (`User`) are verified (owner's decision); any other kind of account is never verified.
 */
final class VerifiedAccounts
{
    private const CACHE = 'chat:verified-accounts';

    /** This request's copy lives in the container (fresh per request, per test, per job). */
    private const MEMO = 'chat.verified-accounts';

    public static function has(?Model $account): bool
    {
        return $account instanceof User && self::hasUser((int) $account->getKey());
    }

    /** By key: `user:7` — the participant keys the chat uses. */
    public static function hasKey(string $type, int|string|null $id): bool
    {
        return $type === 'user' && $id !== null && self::hasUser((int) $id);
    }

    public static function hasUser(int $userId): bool
    {
        $map = self::map();
        if (! array_key_exists($userId, $map)) {
            return false;
        }
        $until = $map[$userId];

        return $until === null || $until > now()->getTimestamp();
    }

    /** @return list<int> the ids of every verified account right now (filters in the dashboard) */
    public static function userIds(): array
    {
        return array_values(array_filter(array_keys(self::map()), fn (int $id) => self::hasUser($id)));
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::MEMO);
        Cache::forget(self::CACHE);
    }

    /** @return array<int, int|null> */
    private static function map(): array
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }

        try {
            $map = Cache::rememberForever(self::CACHE, function () {
                if (! Schema::hasTable('chat_verification_badges')) {
                    return [];
                }

                return ChatVerificationBadge::query()
                    ->whereIn('status', [VerificationBadgeStatus::Temporary->value, VerificationBadgeStatus::Active->value])
                    ->get(['user_id', 'expires_at'])
                    ->mapWithKeys(fn (ChatVerificationBadge $b) => [$b->user_id => $b->expires_at?->getTimestamp()])
                    ->all();
            });
        } catch (Throwable) {
            $map = [];
        }
        app()->instance(self::MEMO, $map);

        return $map;
    }
}
