<?php

namespace Modules\Driver\Http\Controllers\Driver;

use App\Enums\VerificationType;
use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Services\Auth\VerificationCodeService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Http\Requests\DriverOtpRequest;
use Modules\Driver\Http\Requests\DriverVerifyRequest;
use Modules\Driver\Http\Resources\DriverResource;
use Modules\Driver\Models\Driver;

/**
 * Sign-in for the driver app (docs/rides-plan.md §4.1): phone + OTP, the same flow as the rider
 * app but its own account and guard. An unknown phone becomes a new driver who still has to send
 * their documents; only a blocked account is turned away — the others sign in and see why
 * they can't work yet.
 */
class DriverAuthController extends Controller
{
    public function __construct(
        private readonly VerificationCodeService $verificationCodes,
    ) {}

    public function requestOtp(DriverOtpRequest $request): JsonResponse
    {
        $phone = $this->fullPhone($request->validated('dial_code'), $request->validated('phone'));

        $driver = Driver::query()->where('phone', $phone)->first();
        $isNew = $driver === null;

        $driver ??= Driver::query()->create([
            'phone' => $phone,
            'country_id' => $this->countryFor($request->validated('dial_code'))->id,
            'status' => DriverStatus::PendingDocuments,
            'registered_by' => 'app',
            'locale' => app()->getLocale(),
        ]);

        $this->assertCanSignIn($driver);

        $driver->sendPhoneOtp();

        return ApiResponse::success([
            'masked_phone' => $this->maskPhone($phone),
            'is_new_driver' => $isNew,
            'resend_cooldown_seconds' => $driver->phoneOtpCooldownSeconds(),
        ], __('api.phone_otp_sent'));
    }

    public function verifyOtp(DriverVerifyRequest $request): JsonResponse
    {
        $driver = $this->findByPhone($request->validated('dial_code'), $request->validated('phone'));

        $this->assertCanSignIn($driver);

        $this->verificationCodes->verify($driver, VerificationType::Phone, $request->validated('code'));

        $driver->forceFill(['phone_verified_at' => $driver->phone_verified_at ?? now(), 'last_active_at' => now()])->save();

        // One phone signed in at a time: a new sign-in ends the old one.
        $driver->tokens()->delete();
        $token = $driver->createToken((string) config('driver.token_name', 'driver-app'))->plainTextToken;

        return ApiResponse::success([
            'driver' => new DriverResource($driver->fresh()),
            'token' => $token,
            'token_type' => 'Bearer',
        ], __('api.phone_verified'));
    }

    public function resendOtp(DriverOtpRequest $request): JsonResponse
    {
        $driver = $this->findByPhone($request->validated('dial_code'), $request->validated('phone'));

        $this->assertCanSignIn($driver);

        $driver->sendPhoneOtp();

        return ApiResponse::success([
            'masked_phone' => $this->maskPhone($driver->phone),
            'resend_cooldown_seconds' => $driver->phoneOtpCooldownSeconds(),
        ], __('api.phone_otp_sent'));
    }

    public function me(): JsonResponse
    {
        /** @var Driver $driver */
        $driver = request()->user('driver_api');

        return ApiResponse::success(new DriverResource($driver), __('api.retrieved'));
    }

    public function logout(): JsonResponse
    {
        /** @var Driver|null $driver */
        $driver = request()->user('driver_api');

        // The phone's push id stops pointing at this driver, or the next person on the phone
        // would still get their ride requests.
        $playerId = request()->input('player_id');
        if ($driver && is_string($playerId) && $playerId !== '') {
            $driver->notificationDevices()->where('player_id', $playerId)->delete();
        }

        $driver?->currentAccessToken()?->delete();

        return ApiResponse::success([], __('api.logout_success'));
    }

    private function findByPhone(string $dialCode, string $phone): Driver
    {
        return Driver::query()->where('phone', $this->fullPhone($dialCode, $phone))->first()
            ?? throw ValidationException::withMessages(['phone' => [__('api.phone_number_not_found')]]);
    }

    private function assertCanSignIn(Driver $driver): void
    {
        if (! $driver->canSignIn()) {
            throw ValidationException::withMessages(['phone' => [__('api.account_blocked')]]);
        }
    }

    /** The country the number belongs to (DriverOtpRequest already checked it exists and is active). */
    private function countryFor(string $dialCode): Country
    {
        return Country::query()
            ->whereRaw("REPLACE(dial_code, '+', '') = ?", [ltrim($dialCode, '+')])
            ->where('status', true)
            ->firstOrFail();
    }

    /** Same canonical form as the rider app: "+966" + "50…" => "+96650…". */
    private function fullPhone(string $dialCode, string $phone): string
    {
        return '+'.ltrim($dialCode, '+').$phone;
    }

    private function maskPhone(string $fullPhone): string
    {
        $length = mb_strlen($fullPhone);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4).mb_substr($fullPhone, -4);
    }
}
