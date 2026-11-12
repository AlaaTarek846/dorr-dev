<?php

namespace Modules\Freelance\Services\Account;

use App\Enums\VerificationType;
use App\Models\Country;
use App\Models\NotificationDevice;
use App\Services\Auth\VerificationCodeService;
use App\Services\Security\AccountSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerDevice;
use Modules\Freelance\Services\Features\FreelanceFeatures;

/**
 * Sign-in for the freelancer app, website and dashboard (FL-003): phone + OTP like the driver app.
 * An unknown phone becomes a new freelancer who still has to complete their profile; a blocked
 * account is turned away, and so is a country where the freelance service is switched off.
 * One token per device: signing in again on the same device ends that device's old session only.
 */
class FreelancerAuthService
{
    public function __construct(
        private readonly VerificationCodeService $verificationCodes,
        private readonly FreelanceFeatures $features,
        private readonly AccountSessionService $sessions,
    ) {}

    /** @return array{freelancer: Freelancer, is_new: bool} */
    public function requestOtp(string $dialCode, string $phone): array
    {
        $country = $this->countryFor($dialCode);
        $this->assertServiceOpen($country->id);

        $fullPhone = $this->fullPhone($dialCode, $phone);
        $freelancer = Freelancer::query()->where('phone', $fullPhone)->first();
        $isNew = $freelancer === null;

        $freelancer ??= Freelancer::query()->create([
            'phone' => $fullPhone,
            'country_id' => $country->id,
            'status' => FreelancerStatus::Registered,
            'locale' => app()->getLocale(),
        ]);

        $this->assertCanSignIn($freelancer);
        $freelancer->sendPhoneOtp();

        return ['freelancer' => $freelancer, 'is_new' => $isNew];
    }

    public function resendOtp(string $dialCode, string $phone): Freelancer
    {
        $freelancer = $this->findByPhone($dialCode, $phone);
        $this->assertCanSignIn($freelancer);
        $this->assertServiceOpen($freelancer->country_id);
        $freelancer->sendPhoneOtp();

        return $freelancer;
    }

    /**
     * @param  array{device_id?: ?string, device_name?: ?string, platform?: ?string}  $device
     * @return array{freelancer: Freelancer, token: string}
     */
    public function verify(string $dialCode, string $phone, string $code, array $device, ?string $ip, ?string $userAgent): array
    {
        $freelancer = $this->findByPhone($dialCode, $phone);
        $this->assertCanSignIn($freelancer);
        $this->assertServiceOpen($freelancer->country_id);

        $this->verificationCodes->verify($freelancer, VerificationType::Phone, $code);

        $token = DB::transaction(function () use ($freelancer, $device, $ip, $userAgent): string {
            $freelancer->forceFill(['phone_verified_at' => $freelancer->phone_verified_at ?? now(), 'last_active_at' => now()])->save();

            // The same device signing in again replaces its own session, not the others.
            $deviceId = $device['device_id'] ?? null;
            if ($deviceId !== null && $deviceId !== '') {
                $this->endDevices($freelancer->devices()->where('device_id', $deviceId)->get());
            }

            $platform = $device['platform'] ?? null;
            // The shared device sessions and security log (Settings > Security), like the DORR app.
            [$plainToken, $session] = $this->sessions->signIn($freelancer, (string) config('freelance.token_name', 'freelance').($platform ? ':'.$platform : ''));

            FreelancerDevice::query()->create([
                'freelancer_id' => $freelancer->id,
                'token_id' => $session->token_id,
                'device_id' => $deviceId ?: null,
                'device_name' => $device['device_name'] ?? null,
                'platform' => $platform,
                'ip' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'last_active_at' => now(),
            ]);

            return $plainToken;
        });

        return ['freelancer' => $freelancer->fresh(), 'token' => $token];
    }

    public function logout(Freelancer $freelancer): void
    {
        $token = $freelancer->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $this->sessions->signOutCurrent($freelancer);
            $this->endDevices($freelancer->devices()->where('token_id', $token->id)->get());
            $token->delete();
        }
    }

    /** Ends every session (account blocked or deleted). */
    public function endAllSessions(Freelancer $freelancer): void
    {
        $freelancer->tokens()->delete();
        $freelancer->devices()->delete();
        // A blocked or deleted account gets no more push.
        $freelancer->notificationDevices()->delete();
    }

    /** @param iterable<FreelancerDevice> $devices */
    public function endDevices(iterable $devices): void
    {
        foreach ($devices as $device) {
            // Signing a device out also stops its pushes.
            if ($device->device_id !== null && $device->freelancer_id !== null) {
                NotificationDevice::query()->where('owner_type', (new Freelancer)->getMorphClass())->where('owner_id', $device->freelancer_id)->where('device_id', $device->device_id)->delete();
            }
            if ($device->token_id !== null) {
                PersonalAccessToken::query()->whereKey($device->token_id)->delete();
            }
            $device->delete();
        }
    }

    public function maskPhone(string $fullPhone): string
    {
        $length = mb_strlen($fullPhone);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4).mb_substr($fullPhone, -4);
    }

    private function findByPhone(string $dialCode, string $phone): Freelancer
    {
        return Freelancer::query()->where('phone', $this->fullPhone($dialCode, $phone))->first()
            ?? throw ValidationException::withMessages(['phone' => [__('api.phone_number_not_found')]]);
    }

    private function assertCanSignIn(Freelancer $freelancer): void
    {
        if (! $freelancer->canSignIn()) {
            throw ValidationException::withMessages(['phone' => [__('api.account_blocked')]]);
        }
    }

    private function assertServiceOpen(int $countryId): void
    {
        if (! $this->features->allows($countryId, FreelanceFeatures::SERVICE)) {
            throw new FreelanceException('not_available', 403);
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

    /** Same canonical form as the rider and driver apps: "+966" + "50…" => "+96650…". */
    private function fullPhone(string $dialCode, string $phone): string
    {
        return '+'.ltrim($dialCode, '+').$phone;
    }
}
