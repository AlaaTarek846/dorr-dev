<?php

namespace Modules\User\Http\Controllers;

use App\Enums\UserStatus;
use App\Enums\VerificationType;
use App\Http\Controllers\Controller;
use App\Services\Auth\VerificationCodeService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\User\Http\Requests\MobileOtpRequest;
use Modules\User\Http\Requests\MobileVerifyRequest;
use Modules\User\Http\Resources\UserResource;
use Modules\User\Models\User;

class MobileAuthController extends Controller
{
    private const TOKEN_NAME = 'mobile-app';

    public function __construct(
        private readonly VerificationCodeService $verificationCodes,
    ) {}

    /**
     * Combined login/register — if a user with the given phone does not exist
     * it is created on the fly, then a (fixed demo) OTP is sent.
     */
    public function requestOtp(MobileOtpRequest $request): JsonResponse
    {
        $fullPhone = $this->fullPhone($request->validated('dial_code'), $request->validated('phone'));

        /** @var User $user */
        $user = User::query()->where('phone', $fullPhone)->first();

        $isNewUser = false;

        if (! $user) {
            $user = User::query()->create([
                'name' => null,
                'phone' => $fullPhone,
                'phone_verified_at' => null,
                'status' => UserStatus::Active,
            ]);
            $isNewUser = true;
        }

        $this->assertAccountActive($user);

        $user->sendPhoneOtp();

        return ApiResponse::success([
            'masked_phone' => $this->maskPhone($fullPhone),
            'is_new_user' => $isNewUser,
            'resend_cooldown_seconds' => $user->phoneOtpCooldownSeconds(),
        ], __('api.phone_otp_sent'));
    }

    public function verifyOtp(MobileVerifyRequest $request): JsonResponse
    {
        $fullPhone = $this->fullPhone($request->validated('dial_code'), $request->validated('phone'));

        /** @var User $user */
        $user = User::query()->where('phone', $fullPhone)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'phone' => [__('api.phone_number_not_found')],
            ]);
        }

        $this->assertAccountActive($user);

        $this->verificationCodes->verify($user, VerificationType::Phone, $request->validated('code'));

        $user->update(['phone_verified_at' => now()]);

        $user->tokens()->delete();

        $token = $user->createToken(self::TOKEN_NAME)->plainTextToken;

        return ApiResponse::success([
            'user' => new UserResource($user->fresh()),
            'token' => $token,
            'token_type' => 'Bearer',
        ], __('api.phone_verified'));
    }

    public function resendOtp(MobileOtpRequest $request): JsonResponse
    {
        $fullPhone = $this->fullPhone($request->validated('dial_code'), $request->validated('phone'));

        /** @var User $user */
        $user = User::query()->where('phone', $fullPhone)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'phone' => [__('api.phone_number_not_found')],
            ]);
        }

        $this->assertAccountActive($user);

        $user->sendPhoneOtp();

        return ApiResponse::success([
            'masked_phone' => $this->maskPhone($fullPhone),
            'resend_cooldown_seconds' => $user->phoneOtpCooldownSeconds(),
        ], __('api.phone_otp_sent'));
    }

    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user('user_api');
        $user->load(['country.flag']);

        return ApiResponse::success(
            new UserResource($user),
            __('api.retrieved'),
        );
    }

    public function logout(): JsonResponse
    {
        request()->user('user_api')?->currentAccessToken()?->delete();

        return ApiResponse::success([], __('api.logout_success'));
    }

    private function assertAccountActive(User $user): void
    {
        if ($user->status === UserStatus::Blocked) {
            throw ValidationException::withMessages([
                'phone' => [__('api.account_blocked')],
            ]);
        }

        if ($user->status === UserStatus::Inactive) {
            throw ValidationException::withMessages([
                'phone' => [__('api.account_inactive')],
            ]);
        }
    }

    /**
     * Canonical full-phone format matches the dashboard's combined storage,
     * e.g. dial_code "+966" + phone "50..." => "+96650...".
     */
    private function fullPhone(string $dialCode, string $phone): string
    {
        $dial = ltrim($dialCode, '+');

        return '+'.$dial.$phone;
    }

    private function maskPhone(string $fullPhone): string
    {
        $length = mb_strlen($fullPhone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).mb_substr($fullPhone, -4);
    }
}
