<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Enums\VerificationType;
use App\Http\Controllers\Controller;
use App\Services\Auth\VerificationCodeService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Modules\User\Http\Requests\ChangeEmailRequest;
use Modules\User\Http\Requests\ChangePhoneRequest;
use Modules\User\Http\Requests\ConfirmCodeRequest;
use Modules\User\Http\Requests\UpdateAvatarRequest;
use Modules\User\Http\Requests\UpdateIdentityRequest;
use Modules\User\Http\Resources\UserResource;
use Modules\User\Models\User;

class MobileProfileController extends Controller
{
    private const PENDING_PHONE_CACHE_KEY = 'profile:phone-change:';

    private const PENDING_EMAIL_CACHE_KEY = 'profile:email-change:';

    public function __construct(
        private readonly VerificationCodeService $verificationCodes,
    ) {}

    /**
     * Step 1 of the phone change: validate the new number, remember it, and
     * send an OTP to it. Nothing on the user row changes until confirm.
     */
    public function requestPhoneChange(ChangePhoneRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');
        $fullPhone = $this->fullPhone($request->validated('dial_code'), $request->validated('phone'));

        $user->sendPhoneOtp();

        Cache::put(
            self::PENDING_PHONE_CACHE_KEY.$user->getKey(),
            $fullPhone,
            now()->addMinutes((int) config('auth_flow.otp_expiry_minutes', 10)),
        );

        return ApiResponse::success([
            'masked_phone' => $this->maskPhone($fullPhone),
            'resend_cooldown_seconds' => $user->phoneOtpCooldownSeconds(),
        ], __('api.phone_otp_sent'));
    }

    /**
     * Step 2 of the phone change: a valid code swaps the old number for the
     * pending one and re-marks the phone verified.
     */
    public function confirmPhoneChange(ConfirmCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $pending = Cache::get(self::PENDING_PHONE_CACHE_KEY.$user->getKey());

        if (! is_string($pending) || $pending === '') {
            throw ValidationException::withMessages([
                'code' => [__('api.change_request_expired')],
            ]);
        }

        $this->verificationCodes->verify($user, VerificationType::Phone, $request->validated('code'));

        $user->update([
            'phone' => $pending,
            'phone_verified_at' => now(),
        ]);

        Cache::forget(self::PENDING_PHONE_CACHE_KEY.$user->getKey());

        return ApiResponse::success(
            new UserResource($this->freshUser($user)),
            __('api.phone_verified'),
        );
    }

    /**
     * Name + gender need no verification — saved straight away.
     */
    public function updateIdentity(UpdateIdentityRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $user->update($request->validated());

        return ApiResponse::success(
            new UserResource($this->freshUser($user)),
            __('api.updated'),
        );
    }

    /**
     * Replace the avatar with the uploaded image (old file removed).
     */
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $user->setSingleMedia('avatar', $request->file('avatar'));

        return ApiResponse::success(
            new UserResource($this->freshUser($user)),
            __('api.updated'),
        );
    }

    /**
     * Step 1 of the email change: validate the new address, remember it, and
     * mail an OTP to it. Nothing on the user row changes until confirm.
     */
    public function requestEmailChange(ChangeEmailRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');
        $email = $request->validated('email');

        $this->verificationCodes->send($user, VerificationType::Email, $email);

        Cache::put(
            self::PENDING_EMAIL_CACHE_KEY.$user->getKey(),
            $email,
            now()->addMinutes((int) config('auth_flow.otp_expiry_minutes', 10)),
        );

        return ApiResponse::success([
            'masked_email' => $this->verificationCodes->maskEmail($email),
            'resend_cooldown_seconds' => (int) config('auth_flow.otp_resend_cooldown_seconds', 60),
        ], __('api.email_otp_sent'));
    }

    /**
     * Step 2 of the email change: a valid code swaps the old address for the
     * pending one and marks the email verified.
     */
    public function confirmEmailChange(ConfirmCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $pending = Cache::get(self::PENDING_EMAIL_CACHE_KEY.$user->getKey());

        if (! is_string($pending) || $pending === '') {
            throw ValidationException::withMessages([
                'code' => [__('api.change_request_expired')],
            ]);
        }

        $this->verificationCodes->verify($user, VerificationType::Email, $request->validated('code'));

        $user->update([
            'email' => $pending,
            'email_verified_at' => now(),
        ]);

        Cache::forget(self::PENDING_EMAIL_CACHE_KEY.$user->getKey());

        return ApiResponse::success(
            new UserResource($this->freshUser($user)),
            __('api.email_verified'),
        );
    }

    /**
     * Soft-delete the user's account and revoke all authentication tokens
     * immediately, kicking the user out of the app.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $user->tokens()->delete();
        $user->delete();

        return ApiResponse::success([], __('api.account_deleted'));
    }

    /**
     * UserResource only serialises `country` when the relation is loaded, and the
     * app replaces its cached session user with every profile response — so a
     * plain fresh() would silently drop the country (dial code + flag) from the
     * payload and the client would fall back to the wrong default country.
     */
    private function freshUser(User $user): User
    {
        return $user->fresh()->load(['country.flag']);
    }

    /**
     * Canonical full-phone format matches the login flow, e.g. dial_code
     * "+966" + phone "50..." => "+96650...".
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
