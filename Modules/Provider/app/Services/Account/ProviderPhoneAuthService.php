<?php

namespace Modules\Provider\Services\Account;

use App\Enums\UserStatus;
use App\Enums\VerificationType;
use App\Models\Country;
use App\Services\Auth\VerificationCodeService;
use App\Services\Security\AccountSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Provider\Models\Provider;
use Modules\Provider\Services\Establishments\MembershipService;

/**
 * Phone + OTP sign-in for the providers app (A-02), like the captain and freelancer apps. An unknown phone becomes
 * a new account that still has to tell us who it is and which service it offers; invitations sent to that phone
 * become theirs as soon as the phone is verified (§14). The web portal keeps its email sign-in.
 */
class ProviderPhoneAuthService
{
    public function __construct(
        private readonly VerificationCodeService $verificationCodes,
        private readonly AccountSessionService $sessions,
        private readonly MembershipService $memberships,
    ) {}

    /** @return array{provider: Provider, is_new: bool} */
    public function requestOtp(string $dialCode, string $phone): array
    {
        $country = $this->countryFor($dialCode);
        $fullPhone = $this->fullPhone($dialCode, $phone);
        $provider = Provider::query()->where('phone', $fullPhone)->first();
        $isNew = $provider === null;

        $provider ??= Provider::query()->create([
            'phone' => $fullPhone,
            'country_id' => $country->id,
            'status' => UserStatus::Active,
        ]);

        $this->assertCanSignIn($provider);
        $provider->sendPhoneOtp();

        return ['provider' => $provider, 'is_new' => $isNew];
    }

    public function resendOtp(string $dialCode, string $phone): Provider
    {
        $provider = $this->findByPhone($dialCode, $phone);
        $this->assertCanSignIn($provider);
        $provider->sendPhoneOtp();

        return $provider;
    }

    /** @return array{provider: Provider, token: string} */
    public function verify(string $dialCode, string $phone, string $code, ?string $platform): array
    {
        $provider = $this->findByPhone($dialCode, $phone);
        $this->assertCanSignIn($provider);
        $this->verificationCodes->verify($provider, VerificationType::Phone, $code);

        $token = DB::transaction(function () use ($provider, $platform): string {
            $provider->forceFill(['phone_verified_at' => $provider->phone_verified_at ?? now()])->save();
            $this->memberships->claimInvitations($provider);

            [$plainToken] = $this->sessions->signIn($provider, 'provider-app'.($platform ? ':'.$platform : ''));

            return $plainToken;
        });

        return ['provider' => $provider->fresh(), 'token' => $token];
    }

    public function logout(Provider $provider, ?string $playerId): void
    {
        // The phone's push id stops pointing at this person, or the next one on the phone would get their alerts.
        if ($playerId !== null && $playerId !== '') {
            $provider->notificationDevices()->where('player_id', $playerId)->delete();
        }

        $token = $provider->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $this->sessions->signOutCurrent($provider);
            $token->delete();
        }
    }

    public function maskPhone(string $fullPhone): string
    {
        $length = mb_strlen($fullPhone);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4).mb_substr($fullPhone, -4);
    }

    private function findByPhone(string $dialCode, string $phone): Provider
    {
        return Provider::query()->where('phone', $this->fullPhone($dialCode, $phone))->first()
            ?? throw ValidationException::withMessages(['phone' => [__('api.phone_number_not_found')]]);
    }

    private function assertCanSignIn(Provider $provider): void
    {
        if (! $provider->canSignIn()) {
            throw ValidationException::withMessages(['phone' => [__('api.account_blocked')]]);
        }
    }

    /** The country the number belongs to (the request already checked it exists and is active). */
    private function countryFor(string $dialCode): Country
    {
        return Country::query()
            ->whereRaw("REPLACE(dial_code, '+', '') = ?", [ltrim($dialCode, '+')])
            ->where('status', true)
            ->firstOrFail();
    }

    /** Same canonical form as the other apps: "+966" + "50…" => "+96650…". */
    private function fullPhone(string $dialCode, string $phone): string
    {
        return '+'.ltrim($dialCode, '+').$phone;
    }
}
