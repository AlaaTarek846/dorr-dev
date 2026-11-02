<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Support\VerifiedAccounts;

/**
 * Whether an account shows the verification ✔ next to its name (account verification, AV-026) — for any
 * module that prints a person's name, without depending on the chat module being there. One cached map,
 * so asking for every row of a list costs no query.
 */
final class AccountBadge
{
    public static function verified(?Model $account): bool
    {
        return $account !== null && class_exists(VerifiedAccounts::class) && VerifiedAccounts::has($account);
    }

    /** @return list<int> every verified Dorr account right now (the dashboard's "verified" filter) */
    public static function userIds(): array
    {
        return class_exists(VerifiedAccounts::class) ? VerifiedAccounts::userIds() : [];
    }
}
