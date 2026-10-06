<?php

namespace Modules\User\Http\Controllers\Mobile;

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
     *
     * A soft-deleted account (deleted_at set) is NOT signed back in here: we
     * return a clear "account_state = deleted" signal and let the app offer a
     * Restore step. Only a later OTP verify (verifyOtp) sets deleted_at = null.
     */
    public function requestOtp(MobileOtpRequest $request): JsonResponse
    {
        $fullPhone = $this->fullPhone($request->validated('dial_code'), $request->validated('phone'));

        /** @var User|null $user */
        $user = User::query()->withTrashed()->where('phone', $fullPhone)->first();

        $isNewUser = false;

        if (! $user) {
            $user = User::query()->create([
                'name' => null,
                'phone' => $fullPhone,
                'phone_verified_at' => null,
                'status' => UserStatus::Active,
            ]);
            $isNewUser = true;
        } elseif ($user->trashed()) {
            // Deleted but restorable — tell the app instead of logging in directly.
            // No OTP is sent (and nothing restored) until the user explicitly asks
            // for the restore flow via requestRestoreOtp.
            return ApiResponse::success([
                'masked_phone' => $this->maskPhone($fullPhone),
                'account_state' => 'deleted',
            ], __('api.account_deleted_restore'));
        }

        $this->assertAccountActive($user);

        $user->sendPhoneOtp();

        return ApiResponse::success([
            'masked_phone' => $this->maskPhone($fullPhone),
            'is_new_user' => $isNewUser,
            'account_state' => 'active',
            'resend_cooldown_seconds' => $user->phoneOtpCooldownSeconds(),
        ], __('api.phone_otp_sent'));
    }

    /**
     * Restore step: a soft-deleted account asks for a code so its owner can prove
     * the phone still belongs to them. Nothing is restored yet — verifyOtp only
     * sets deleted_at = null once the code checks out.
     */
    public function requestRestoreOtp(MobileOtpRequest $request): JsonResponse
    {
        $fullPhone = $this->fullPhone($request->validated('dial_code'), $request->validated('phone'));

        /** @var User|null $user */
        $user = User::query()->withTrashed()->where('phone', $fullPhone)->first();

        if (! $user || ! $user->trashed()) {
            throw ValidationException::withMessages([
                'phone' => [__('api.account_not_deleted')],
            ]);
        }

        $this->assertAccountActive($user);

        $user->sendPhoneOtp();

        return ApiResponse::success([
            'masked_phone' => $this->maskPhone($fullPhone),
            'account_state' => 'restore',
            'resend_cooldown_seconds' => $user->phoneOtpCooldownSeconds(),
        ], __('api.restore_otp_sent'));
    }

    public function verifyOtp(MobileVerifyRequest $request): JsonResponse
    {
        $fullPhone = $this->fullPhone($request->validated('dial_code'), $request->validated('phone'));

        /** @var User|null $user */
        // withTrashed() finds accounts still soft-deleted mid-restore flow.
        $user = User::query()->withTrashed()->where('phone', $fullPhone)->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'phone' => [__('api.phone_number_not_found')],
            ]);
        }

        $this->assertAccountActive($user);

        $this->verificationCodes->verify($user, VerificationType::Phone, $request->validated('code'));

        // Restore only AFTER the code is verified, so a wrong code can never revive
        // an account (deleted_at stays set) or create a sign-in token for it.
        $isRestored = $user->trashed();
        if ($isRestored) {
            $user->restore();
        }

        $user->update(['phone_verified_at' => now()]);

        $user->tokens()->delete();

        app(\App\Services\General\LoginCountry::class)->remember($user);
        $token = $user->createToken(self::TOKEN_NAME)->plainTextToken;

        return ApiResponse::success([
            'user' => new UserResource($user->fresh()->load(['country.flag'])),
            'token' => $token,
            'token_type' => 'Bearer',
            'is_restored' => $isRestored,
        ], $isRestored ? __('api.account_restored') : __('api.phone_verified'));
    }

    public function resendOtp(MobileOtpRequest $request): JsonResponse
    {
        $fullPhone = $this->fullPhone($request->validated('dial_code'), $request->validated('phone'));

        /** @var User|null $user */
        // withTrashed() mirrors requestOtp/requestRestoreOtp — a code may be resent
        // while the account is still soft-deleted (the restore happens on verify).
        $user = User::query()->withTrashed()->where('phone', $fullPhone)->first();

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
        $user = request()->user('user_api');

        // The phone's push id stops pointing at this account, or the next person on this phone
        // would still get its messages and calls.
        $playerId = request()->input('player_id');
        if ($user && is_string($playerId) && $playerId !== '') {
            $user->notificationDevices()->where('player_id', $playerId)->delete();
        }

        $user?->currentAccessToken()?->delete();

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
