<?php

namespace Modules\User\Repositories;

use Modules\User\Models\User;
use Modules\User\Models\UserMobileAppearance;

class UserMobileAppearanceRepository
{
    public function forUser(User $user): ?UserMobileAppearance
    {
        return UserMobileAppearance::query()
            ->where('user_id', $user->id)
            ->first();
    }

    public function upsertForUser(User $user, array $attributes): UserMobileAppearance
    {
        return UserMobileAppearance::query()->updateOrCreate(
            ['user_id' => $user->id],
            $attributes,
        );
    }
}
