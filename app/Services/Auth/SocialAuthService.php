<?php

namespace App\Services\Auth;

use App\Enums\SocialProvider;
use App\Enums\UserStatus;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use RuntimeException;

class SocialAuthService
{
    /**
     * @param  class-string<Model>  $userModelClass
     * @return array{user: Model, is_new: bool, email_verified: bool, needs_password: bool}
     */
    public function authenticate(
        SocialProvider $provider,
        SocialiteUser $socialUser,
        string $userModelClass,
        bool $allowRegistration = true,
    ): array {
        if (! class_exists($userModelClass)) {
            throw new RuntimeException('Invalid authenticatable model.');
        }

        $providerId = (string) $socialUser->getId();
        $email = $socialUser->getEmail();
        $name = $socialUser->getName() ?: ($email ? Str::before($email, '@') : 'User');

        return DB::transaction(function () use ($provider, $providerId, $email, $name, $userModelClass, $socialUser, $allowRegistration) {
            /** @var SocialAccount|null $linkedAccount */
            $linkedAccount = SocialAccount::query()
                ->where('provider', $provider->value)
                ->where('provider_id', $providerId)
                ->where('authenticatable_type', (new $userModelClass)->getMorphClass())
                ->first();

            if ($linkedAccount) {
                /** @var Model $user */
                $user = $userModelClass::query()->findOrFail($linkedAccount->authenticatable_id);

                $this->assertUserCanAuthenticate($user);

                return $this->buildResult($user, false);
            }

            /** @var Model|null $existingUser */
            $existingUser = $email
                ? $userModelClass::query()->where('email', $email)->first()
                : null;

            if ($existingUser) {
                $this->assertUserCanAuthenticate($existingUser);
                $this->linkAccount($existingUser, $provider, $providerId, $email);

                if (! $existingUser->email_verified_at && $email) {
                    $existingUser->forceFill(['email_verified_at' => now()])->save();
                }

                return $this->buildResult($existingUser, false);
            }

            if (! $allowRegistration) {
                throw new RuntimeException(__('api.provider_account_not_found'));
            }

            if (! $email) {
                throw new RuntimeException(__('api.social_email_required'));
            }

            /** @var Model $user */
            $user = $userModelClass::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => null,
                'status' => UserStatus::Active->value,
                'email_verified_at' => now(),
            ]);

            $this->linkAccount($user, $provider, $providerId, $email);

            return $this->buildResult($user, true);
        });
    }

    /**
     * @return array{user: Model, is_new: bool, email_verified: bool, needs_password: bool}
     */
    private function buildResult(Model $user, bool $isNew): array
    {
        return [
            'user' => $user->fresh(),
            'is_new' => $isNew,
            'email_verified' => (bool) $user->email_verified_at,
            'needs_password' => empty($user->password),
        ];
    }

    private function linkAccount(Model $user, SocialProvider $provider, string $providerId, ?string $email): void
    {
        $user->socialAccounts()->updateOrCreate(
            [
                'provider' => $provider->value,
                'provider_id' => $providerId,
            ],
            [
                'email' => $email,
            ],
        );
    }

    private function assertUserCanAuthenticate(Model $user): void
    {
        $status = $user->status ?? null;

        if ($status instanceof UserStatus) {
            if ($status === UserStatus::Blocked) {
                throw new RuntimeException(__('api.account_blocked'));
            }

            if ($status === UserStatus::Inactive) {
                throw new RuntimeException(__('api.account_inactive'));
            }
        }
    }
}
